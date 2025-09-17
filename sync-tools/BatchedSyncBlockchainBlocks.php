<?php
namespace App\Jobs;

use App\Services\BlockchainService;
use App\Services\NknRpcClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class BatchedSyncBlockchainBlocks implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public array $heights;
    public int $batchSize;

    /**
     * Maximum retry attempts for this job
     */
    public int $tries = 3;

    /**
     * Job timeout in seconds (5 minutes - reduced for faster processing)
     */
    public int $timeout = 300;

    /**
     * Create a new job instance.
     *
     * @param array $heights Array of block heights to sync
     */
    public function __construct(array $heights)
    {
        $this->heights = $heights;
        $this->batchSize = count($heights);
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle(): void
    {
        $blockchainService = app(BlockchainService::class);
        $rpcClient = app(NknRpcClient::class);

        $startHeight = min($this->heights);
        $endHeight = max($this->heights);
        
        Log::info("Syncing batch of {$this->batchSize} blocks: {$startHeight} to {$endHeight} (attempt {$this->attempts()})");
        
        try {
            // Fetch all blocks in the batch - NO RATE LIMITING CONSTRAINTS
            Log::info("Fetching batch of {$this->batchSize} blocks in parallel - rate limits disabled");
            
            $blocks = $rpcClient->getBlocksBatch($this->heights);
            $successCount = 0;
            $failedHeights = [];

            // Process each block in the batch
            foreach ($this->heights as $height) {
                if (isset($blocks[$height]) && $blocks[$height] !== null) {
                    try {
                        $blockchainService->syncBlockWithData($height, $blocks[$height]);
                        $successCount++;
                    } catch (\Exception $e) {
                        Log::error("Failed to sync block {$height} in batch: " . $e->getMessage());
                        $failedHeights[] = $height;
                    }
                } else {
                    $failedHeights[] = $height;
                }
            }

            Log::info("Batch complete: {$successCount}/{$this->batchSize} blocks synced successfully");

            // Handle failures - simplified since no rate limiting
            if ($successCount === 0) {
                // Complete batch failure - retry
                throw new \Exception("Batch failed completely - all {$this->batchSize} blocks failed");
            } elseif (!empty($failedHeights)) {
                // Partial success - log failures but don't fail entire job
                Log::warning("Partial batch success: " . count($failedHeights) . " blocks failed: " . implode(', ', array_slice($failedHeights, 0, 10)));
                
                // Only retry if more than 50% failed
                if (count($failedHeights) > ($this->batchSize / 2)) {
                    throw new \Exception("High failure rate: " . count($failedHeights) . "/{$this->batchSize} blocks failed");
                }
            }

            // REMOVED: No more artificial delays - process at full speed!
            
        } catch (\Exception $e) {
            Log::error("Batch processing failed: " . $e->getMessage());
            
            // Simple retry logic - no rate limiting delays
            if ($this->attempts() < $this->tries) {
                Log::info("Retrying batch after 30 seconds (attempt {$this->attempts()}/{$this->tries})");
                $this->release(30);  // Shorter delay since no rate limits
                return;
            }
            
            // Otherwise, fail the job
            throw $e;
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Exception $exception): void
    {
        Log::error("BatchedSyncBlockchainBlocks job failed permanently for batch {$this->batchSize} blocks starting at " . min($this->heights) . ": " . $exception->getMessage());
        Log::error("Failed block range: " . min($this->heights) . " to " . max($this->heights));
        
        // Could implement individual block retry here if needed
        // foreach ($this->heights as $height) {
        //     ProcessSingleBlock::dispatch($height)->onQueue('failed_blocks')->delay(60);
        // }
    }
}
