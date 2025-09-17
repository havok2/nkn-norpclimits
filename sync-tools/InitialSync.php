<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Jobs\SyncBlockchainBlock;
use Illuminate\Support\Facades\DB;

class InitialSync extends Command
{
    protected $signature = 'blockchain:init {--batch-size=100 : Batch size for processing} {--chunk-size=1000 : Number of jobs to queue per chunk} {--queue-threshold=100 : Queue size threshold before waiting}';
    protected $description = 'Intelligent blockchain sync: fills gaps and syncs new blocks with smart chunking';

    private function fetchNodeHeight()
    {
        $url = config('services.nkn.rpc_url', 'http://127.0.0.1:30003');
        
        $payload = json_encode([
            'jsonrpc' => '2.0',
            'method' => 'getblockcount',  // Changed back from getlatestblockheight
            'params' => new \stdClass(),
            'id' => 1,
        ]);

        $opts = [
            'http' => [
                'method'  => 'POST',
                'header'  => 'Content-Type: application/json\r\n',
                'content' => $payload,
                'timeout' => 10,
            ],
        ];

        $context = stream_context_create($opts);
        $result = @file_get_contents($url, false, $context);

        if ($result === false) {
            $this->error("Failed to fetch node height from {$url}!");
            return null;
        }

        $decoded = json_decode($result, true);
        
        if (!$decoded || isset($decoded['error'])) {
            $this->error("RPC error: " . ($decoded['error']['message'] ?? 'Unknown error'));
            return null;
        }

        return $decoded['result'] ?? null;
    }

    public function handle()
    {
        $this->info('Starting Intelligent Blockchain Sync...');
        
        // Get batch configuration
        $batchSize = (int) $this->option('batch-size');
        $chunkSize = (int) $this->option('chunk-size');
        $queueThreshold = (int) $this->option('queue-threshold');
        
        // Get current node height
        $nodeHeight = $this->fetchNodeHeight();
        if ($nodeHeight === null) {
            $this->error('Node height could not be retrieved. Aborting.');
            return 1;
        }
        
        $this->info("Node height: {$nodeHeight}");
        
        // Get database statistics
        $dbStats = DB::select("
            SELECT 
                COUNT(*) as total_blocks,
                COALESCE(MIN(height), 0) as min_height,
                COALESCE(MAX(height), 0) as max_height
            FROM blocks
        ")[0];
        
        $this->info("Database stats: {$dbStats->total_blocks} blocks, range {$dbStats->min_height}-{$dbStats->max_height}");
        
        // Find gaps and missing blocks
        $missingBlocks = [];
        
        // Check for gaps in existing data
        if ($dbStats->total_blocks > 0) {
            $gaps = DB::select("
                SELECT 
                    (lag_height + 1) as gap_start,
                    (height - 1) as gap_end
                FROM (
                    SELECT 
                        height,
                        LAG(height, 1, -1) OVER (ORDER BY height) as lag_height
                    FROM blocks 
                    ORDER BY height
                ) t
                WHERE height - lag_height > 1
            ");
            
            foreach ($gaps as $gap) {
                for ($h = $gap->gap_start; $h <= $gap->gap_end; $h++) {
                    $missingBlocks[] = $h;
                }
            }
        }
        
        // Add new blocks beyond current database
        $startHeight = max($dbStats->max_height + 1, 0);
        for ($h = $startHeight; $h <= $nodeHeight; $h++) {
            $missingBlocks[] = $h;
        }
        
        $totalMissing = count($missingBlocks);
        
        if ($totalMissing === 0) {
            $this->info('Blockchain is fully synchronized!');
            return 0;
        }
        
        $totalBatches = ceil($totalMissing / $batchSize);
        $this->info("Syncing {$totalMissing} blocks in {$totalBatches} batches of {$batchSize} blocks each...");
        $this->info("Using intelligent chunking: {$chunkSize} jobs per chunk, waiting for queue < {$queueThreshold}");
        
        $jobsQueued = 0;
        $chunks = array_chunk($missingBlocks, $chunkSize);
        
        foreach ($chunks as $chunkIndex => $chunk) {
            // Wait for queue to clear if needed
            while (true) {
                $queueSize = \Illuminate\Support\Facades\Redis::llen('queues:blockchainCrawler');
                if ($queueSize < $queueThreshold) {
                    break;
                }
                $this->info("Queue size: {$queueSize}. Waiting...");
                sleep(2);
            }
            
            // Queue jobs for this chunk
            $batches = array_chunk($chunk, $batchSize);
            foreach ($batches as $batch) {
                SyncBlockchainBlock::dispatch($batch)->onQueue('blockchainCrawler');
                $jobsQueued++;
            }
            
            $processed = ($chunkIndex + 1) * $chunkSize;
            $this->info("Queued chunk " . ($chunkIndex + 1) . "/" . count($chunks) . " (processed {$processed}/{$totalMissing} blocks)");
        }
        
        $this->info("Sync complete: queued {$jobsQueued} batches ({$totalMissing} blocks) in " . (time() - LARAVEL_START) . "s");
        return 0;
    }
}
