<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Jobs\BatchedSyncBlockchainBlocks;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

class InitialSync extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'blockchain:init 
                           {--batch-size=5 : Number of blocks per batch}
                           {--start-height= : Specific starting height (default: auto-detect)}
                           {--end-height= : Specific ending height (default: current node height)}
                           {--fill-gaps : Fill gaps in existing data instead of syncing from max height}
                           {--max-gap-size=1000 : Maximum gap size to fill in one command}
                           {--chunk-size=1000 : Maximum jobs to queue at once}
                           {--queue-threshold=100 : Wait for queue to drop below this before queuing more}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Intelligent blockchain sync: fills gaps and syncs new blocks with smart chunking';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $this->info("Starting Intelligent Blockchain Sync...");

        // Get options
        $batchSize = (int) $this->option('batch-size');
        $startHeight = $this->option('start-height');
        $endHeight = $this->option('end-height');
        $fillGaps = $this->option('fill-gaps');
        $maxGapSize = (int) $this->option('max-gap-size');

        // Get node height
        $nodeHeight = $this->getNodeHeight();
        if ($nodeHeight === null || $nodeHeight === 0) {
            $this->error("Node height could not be retrieved. Aborting.");
            return 1;
        }
        $this->info("Node height: $nodeHeight");

        // Get database stats
        $dbStats = $this->getDatabaseStats();
        $this->info("Database stats: {$dbStats['count']} blocks, range {$dbStats['min']}-{$dbStats['max']}");

        if ($fillGaps) {
            return $this->fillGaps($batchSize, $maxGapSize);
        }

        // Determine sync strategy
        if ($startHeight !== null) {
            $start = (int) $startHeight;
        } elseif ($dbStats['count'] === 0) {
            // Fresh database - start from genesis
            $start = 0;
            $this->info("Empty database detected - starting from genesis block");
        } else {
            // Check for gaps first
            $gaps = $this->findGaps($dbStats['min'], $dbStats['max'], 10); // Check first 10 gaps
            
            if (!empty($gaps)) {
                $totalGapBlocks = array_sum(array_map(function($gap) {
                    return $gap['end'] - $gap['start'] + 1;
                }, $gaps));
                
                $this->warn("Found " . count($gaps) . " gaps totaling $totalGapBlocks missing blocks");
                $this->info("Example gaps: " . json_encode(array_slice($gaps, 0, 3)));
                
                if ($totalGapBlocks > 1000) {
                    $this->warn("Large gaps detected. Consider using --fill-gaps to address them first.");
                    if (!$this->confirm("Continue syncing from max height instead of filling gaps?")) {
                        return 0;
                    }
                }
            }
            
            // Sync from max height + 1
            $start = $dbStats['max'] + 1;
        }

        $end = $endHeight ? (int) $endHeight : $nodeHeight;
        
        if ($start > $end) {
            $this->info("Nothing to sync. Local height ($start) >= target height ($end)");
            return 0;
        }

        return $this->syncRange($start, $end, $batchSize);
    }

    /**
     * Fill gaps in existing blockchain data
     */
    private function fillGaps(int $batchSize, int $maxGapSize): int
    {
        $this->info("Scanning for gaps in blockchain data...");
        
        $dbStats = $this->getDatabaseStats();
        if ($dbStats['count'] === 0) {
            $this->info("No blocks in database - use normal sync instead");
            return 0;
        }

        $gaps = $this->findGaps($dbStats['min'], $dbStats['max']);
        
        if (empty($gaps)) {
            $this->info("No gaps found in range {$dbStats['min']}-{$dbStats['max']}");
            return 0;
        }

        $this->info("Found " . count($gaps) . " gaps to fill:");
        
        $totalQueued = 0;
        foreach ($gaps as $i => $gap) {
            $gapSize = $gap['end'] - $gap['start'] + 1;
            
            if ($gapSize > $maxGapSize) {
                $this->warn("Skipping large gap {$gap['start']}-{$gap['end']} ($gapSize blocks) - exceeds max size $maxGapSize");
                continue;
            }
            
            $this->info("Filling gap " . ($i + 1) . ": {$gap['start']}-{$gap['end']} ($gapSize blocks)");
            $queued = $this->syncRange($gap['start'], $gap['end'], $batchSize);
            $totalQueued += $queued;
            
            // Prevent overwhelming the system
            if ($totalQueued > 10000) {
                $this->warn("Queued $totalQueued blocks - stopping to prevent system overload");
                break;
            }
        }

        $this->info("Gap filling complete - queued $totalQueued blocks");
        return 0;
    }

    /**
     * Sync a range of blocks with intelligent chunking
     */
    private function syncRange(int $start, int $end, int $batchSize): int
    {
        $total = $end - $start + 1;
        $totalBatches = ceil($total / $batchSize);
        $chunkSize = (int) $this->option('chunk-size');
        $queueThreshold = (int) $this->option('queue-threshold');
        
        $this->info("Syncing $total blocks in $totalBatches batches of $batchSize blocks each...");
        $this->info("Using intelligent chunking: $chunkSize jobs per chunk, waiting for queue < $queueThreshold");

        $batchCount = 0;
        $currentBatch = [];
        $jobsInCurrentChunk = 0;
        $totalJobsQueued = 0;
        $startTime = time();

        for ($height = $start; $height <= $end; $height++) {
            $currentBatch[] = $height;

            if (count($currentBatch) >= $batchSize || $height == $end) {
                // Queue this batch
                BatchedSyncBlockchainBlocks::dispatch($currentBatch)->onQueue('blockchainCrawler');
                $batchCount++;
                $jobsInCurrentChunk++;
                $totalJobsQueued++;

                // Progress reporting
                if ($batchCount % 100 === 0) {
                    $elapsed = time() - $startTime;
                    $rate = $elapsed > 0 ? round($totalJobsQueued / $elapsed, 1) : 0;
                    $this->info("Queued $batchCount/$totalBatches batches ($rate jobs/sec)...");
                }

                $currentBatch = [];

                // Check if we need to wait for queue to drain
                if ($jobsInCurrentChunk >= $chunkSize) {
                    $this->info("Chunk complete - $jobsInCurrentChunk jobs queued. Waiting for queue to drain...");
                    $this->waitForQueueToDrain($queueThreshold);
                    $jobsInCurrentChunk = 0;
                }
            }
        }

        $totalTime = time() - $startTime;
        $this->info("Sync complete: queued $batchCount batches ($total blocks) in {$totalTime}s");
        return $total;
    }

    /**
     * Wait for the queue to drain below threshold
     */
    private function waitForQueueToDrain(int $threshold): void
    {
        $queueSize = $this->getQueueSize();
        
        if ($queueSize <= $threshold) {
            $this->info("Queue size ($queueSize) already below threshold ($threshold)");
            return;
        }

        $this->info("Current queue size: $queueSize - waiting for it to drop below $threshold...");
        $waitStart = time();
        $lastReported = 0;

        while ($queueSize > $threshold) {
            sleep(30); // Check every 30 seconds
            $queueSize = $this->getQueueSize();
            
            $elapsed = time() - $waitStart;
            
            // Report progress every 5 minutes
            if ($elapsed - $lastReported >= 300) {
                $this->info("Still waiting... Queue: $queueSize (target: <$threshold) - waited {$elapsed}s");
                $lastReported = $elapsed;
            }
        }

        $totalWaitTime = time() - $waitStart;
        $this->info("Queue drained to $queueSize in {$totalWaitTime}s - resuming sync");
    }

    /**
     * Get current queue size
     */
    private function getQueueSize(): int
    {
        try {
            return Redis::llen('queues:blockchainCrawler') ?? 0;
        } catch (\Exception $e) {
            $this->warn("Could not get queue size: " . $e->getMessage());
            return 0;
        }
    }

    /**
     * Find gaps in blockchain data
     */
    private function findGaps(int $minHeight, int $maxHeight, int $limit = null): array
    {
        // This query finds missing sequences in the blockchain
        $query = "
            WITH expected_heights AS (
                SELECT generate_series($1::int, $2::int) AS height
            ),
            missing_heights AS (
                SELECT eh.height
                FROM expected_heights eh
                LEFT JOIN blocks b ON eh.height = b.height
                WHERE b.height IS NULL
                ORDER BY eh.height
            ),
            gap_groups AS (
                SELECT 
                    height,
                    height - ROW_NUMBER() OVER (ORDER BY height) AS gap_group
                FROM missing_heights
            )
            SELECT 
                MIN(height) as start_height,
                MAX(height) as end_height,
                COUNT(*) as gap_size
            FROM gap_groups
            GROUP BY gap_group
            ORDER BY start_height
        ";

        if ($limit) {
            $query .= " LIMIT $limit";
        }

        $results = DB::select($query, [$minHeight, $maxHeight]);
        
        return array_map(function($row) {
            return [
                'start' => $row->start_height,
                'end' => $row->end_height,
                'size' => $row->gap_size
            ];
        }, $results);
    }

    /**
     * Get database statistics
     */
    private function getDatabaseStats(): array
    {
        $count = DB::table('blocks')->count();
        $min = $count > 0 ? DB::table('blocks')->min('height') : 0;
        $max = $count > 0 ? DB::table('blocks')->max('height') : 0;

        return [
            'count' => $count,
            'min' => $min,
            'max' => $max
        ];
    }

    /**
     * Get the current height from the remote NKN node
     */
    private function getNodeHeight(): ?int
    {
        $url = config('services.nkn.rpc_url');
        
        if (!$url) {
            $this->error('services.nkn.rpc_url not configured in config/services.php!');
            return null;
        }

        $payload = json_encode([
            'jsonrpc' => '2.0',
            'method' => 'getlatestblockheight',
            'params' => new \stdClass(),
            'id' => 1,
        ]);

        $opts = ['http' => [
            'method'  => "POST",
            'header'  => "Content-Type: application/json\r\n",
            'content' => $payload,
            'timeout' => 10,
        ]];

        $context = stream_context_create($opts);
        $result = @file_get_contents($url, false, $context);

        if ($result === false) {
            $this->error("Failed to fetch node height from $url!");
            return null;
        }

        $json = json_decode($result, true);
        if (!isset($json['result'])) {
            $this->error("Invalid response from node: " . substr($result, 0, 500));
            return null;
        }

        return (int) $json['result'];
    }
}
