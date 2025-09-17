<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Promise;

class NknRpcClient
{
    protected $client;
    protected $baseUri;
    protected $timeout;

    public function __construct()
    {
        $this->baseUri = 'http://' . env('REMOTENODE_ADDR', '127.0.0.1') . ':' . env('REMOTENODE_PORT', '30003');
        $this->timeout = 30;
        
        $this->client = new Client([
            'base_uri' => $this->baseUri,
            'timeout' => $this->timeout,
            'connect_timeout' => 10,
        ]);
    }

    /**
     * Make a single RPC call
     */
    public function call(string $method, array $params = []): ?array
    {
        $payload = [
            'jsonrpc' => '2.0',
            'method' => $method,
            'params' => $params,
            'id' => 1
        ];

        try {
            $response = $this->client->post('', [
                'json' => $payload,
                'headers' => $this->getHeaders(),
            ]);

            $body = $response->getBody()->getContents();
            $data = json_decode($body, true);

            if (isset($data['result'])) {
                return $data['result'];
            } elseif (isset($data['error'])) {
                Log::error("RPC Error for method $method: " . json_encode($data['error']));
                return null;
            }

            Log::error("Invalid RPC response for method $method: " . $body);
            return null;

        } catch (RequestException $e) {
            Log::error("RPC Request failed for method $method: " . $e->getMessage());
            return null;
        } catch (\Exception $e) {
            Log::error("RPC Exception for method $method: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Get multiple blocks in parallel - OPTIMIZED FOR LARAVEL 7.x
     */
    public function getBlocksBatch(array $heights): array
    {
        $results = [];
        
        if (empty($heights)) {
            return $results;
        }

        Log::info("Fetching batch of " . count($heights) . " blocks in parallel - rate limits disabled");

        try {
            // Create concurrent HTTP requests using Laravel 7.x compatible Promise handling
            $promises = [];
            foreach ($heights as $height) {
                $payload = [
                    'jsonrpc' => '2.0',
                    'method' => 'getblock',
                    'params' => ['height' => $height],
                    'id' => $height
                ];

                $promises[$height] = $this->client->postAsync('', [
                    'json' => $payload,
                    'headers' => $this->getHeaders(),
                ]);
            }

            // Wait for all requests to complete - Laravel 7.x compatible
            $responses = Promise\settle($promises)->wait();

            // Process responses
            foreach ($responses as $height => $response) {
                if ($response['state'] === 'fulfilled') {
                    try {
                        $body = $response['value']->getBody()->getContents();
                        $data = json_decode($body, true);
                        
                        if (isset($data['result'])) {
                            $results[$height] = $data['result'];
                        } else {
                            Log::error("No result in response for block $height: " . $body);
                            $results[$height] = null;
                        }
                    } catch (\Exception $e) {
                        Log::error("Failed to parse response for block $height: " . $e->getMessage());
                        $results[$height] = null;
                    }
                } else {
                    Log::error("Request failed for block $height: " . $response['reason']->getMessage());
                    $results[$height] = null;
                }
            }

            $successCount = count(array_filter($results));
            Log::info("Parallel batch complete: $successCount/" . count($heights) . " blocks fetched successfully");

        } catch (\Exception $e) {
            Log::error("Parallel batch request failed: " . $e->getMessage());
            
            // Fallback to individual requests if parallel fails
            Log::warning("Falling back to individual sequential requests");
            foreach ($heights as $height) {
                try {
                    $results[$height] = $this->call('getblock', ["height" => $height]);
                } catch (\Exception $e) {
                    Log::error("Failed to fetch block $height individually: " . $e->getMessage());
                    $results[$height] = null;
                }
            }
        }

        return $results;
    }

    /**
     * Get current blockchain height
     */
    public function getBlockCount(): ?int
    {
        $result = $this->call('getlatestblockheight');
        return $result ? (int)$result : null;
    }

    /**
     * Get a single block by height
     */
    public function getBlock(int $height): ?array
    {
        return $this->call('getblock', ['height' => $height]);
    }

    /**
     * Get transaction by hash
     */
    public function getTransaction(string $hash): ?array
    {
        return $this->call('gettransaction', ['hash' => $hash]);
    }

    /**
     * Get headers for HTTP requests
     */
    protected function getHeaders(): array
    {
        return [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ];
    }

    /**
     * Get base URI for requests
     */
    protected function getBaseUri(): string
    {
        return $this->baseUri;
    }
}
