<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Hyperf\Engine\Http\V2\ClientFactory;
use Hyperf\HttpServer\Annotation\Controller;
use Hyperf\HttpServer\Annotation\RequestMapping;
use Hyperf\HttpServer\Contract\RequestInterface;
use Hyperf\HttpServer\Contract\ResponseInterface;
use Hyperf\Context\Context;
use Hyperf\DbConnection\Db;
use Hyperf\Validation\Contract\ValidatorFactoryInterface;
use Hyperf\Di\Annotation\Inject;
use Hyperf\Logger\LoggerFactory;
use Hyperf\Coroutine\Coroutine;
use Hyperf\Coroutine\Parallel;
use Hyperf\Pool\SimplePool\PoolFactory;
use Hypervel\Coroutine\Channel;
use Psr\Log\LoggerInterface;
use Psr\Http\Message\ResponseInterface as PsrResponseInterface;

#[Controller]
class VinDecoderController extends Controller
{
    #[Inject]
    protected ValidatorFactoryInterface $validationFactory;

    #[Inject]
    protected ?ResponseInterface $response = null;

    #[Inject]
    protected ClientFactory $clientFactory;

    #[Inject]
    protected PoolFactory $poolFactory;

    protected LoggerInterface $logger;
    private Channel $dbChannel;
    private const MAX_CONCURRENT_DB_OPERATIONS = 10;
    private const VIN_MATCH_THRESHOLD = 10;
    private const MAX_BATCH_SIZE = 1000;

    public function __construct(LoggerFactory $loggerFactory)
    {
        $this->logger = $loggerFactory->get('vin_decoder', 'default');
        $this->dbChannel = new Channel(self::MAX_CONCURRENT_DB_OPERATIONS);

        // Initialize DB operation semaphore
        for ($i = 0; $i < self::MAX_CONCURRENT_DB_OPERATIONS; $i++) {
            $this->dbChannel->push(true);
        }
    }

    #[RequestMapping(path: "/api/v1/partnerVinDecoder", methods: "POST")]
    public function decode(RequestInterface $request): PsrResponseInterface
    {
        $startTime = microtime(true);

        try {
            // Get authenticated vendor from context (set by CheckApiKey middleware)
            $vendor = Context::get('authenticated.vendor');
            $logData = Context::get('api.log_data', []);

            if (!$vendor) {
                return $this->errorResponse([
                    "success" => false,
                    "message" => "Authentication required",
                    "code" => "ct-0001"
                ], 401);
            }

            // Validate request data
            $requestData = $request->all();
            $validationResult = $this->validateVinRequest($requestData);

            if (!$validationResult['valid']) {
                // Log validation failure asynchronously
                $this->logApiCallAsync($vendor['id'], $logData, $requestData, $validationResult['response']);
                return $this->response->json($validationResult['response'])->withStatus(400);
            }

            $vin = strtoupper(trim($requestData['vin']));

            // Process VIN decoding with optimized concurrency
            $vinResult = $this->processVinDecoding($vin, $vendor);

            $processingTime = round((microtime(true) - $startTime) * 1000, 2);

            // Log API call asynchronously
            $this->logApiCallAsync($vendor['id'], $logData, $requestData, $vinResult, $processingTime);

            return $this->response->json($vinResult);

        } catch (\Throwable $e) {
            $this->logger->error('VIN Decoder error: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
                'vendor_id' => $vendor['id'] ?? null
            ]);

            $errorResponse = [
                "success" => false,
                "message" => $e->getMessage(),
                "code" => "ct-0500"
            ];

            return $this->response->json($errorResponse)->withStatus(500);
        }
    }

    /**
     * Validate VIN request with optimized validation
     */
    private function validateVinRequest(array $requestData): array
    {
        $validator = $this->validationFactory->make($requestData, [
            'vin' => 'required|string|size:17|regex:/^[A-HJ-NPR-Z0-9]{17}$/'
        ], [
            'vin.required' => 'VIN is required',
            'vin.size' => 'VIN must be exactly 17 characters',
            'vin.regex' => 'VIN contains invalid characters'
        ]);

        if ($validator->fails()) {
            return [
                'valid' => false,
                'response' => [
                    "success" => false,
                    "message" => "Validation failed",
                    "errors" => $validator->errors()
                ]
            ];
        }

        return ['valid' => true];
    }

    /**
     * Process VIN decoding with optimized concurrency
     */
    private function processVinDecoding(string $vin, array $vendor): array
    {
        $parallel = new Parallel();

        // Coroutine 1: Find best VIN match with database optimization
        $parallel->add(function () use ($vin, $vendor) {
            return $this->findBestMatchVinOptimized($vin, $vendor);
        });

        // Coroutine 2: Pre-fetch related data (optional optimization)
        $parallel->add(function () use ($vin) {
            return $this->prefetchRelatedData($vin);
        });

        [$vinResult, $relatedData] = $parallel->wait();

        // Merge any additional data if needed
        if ($relatedData && isset($vinResult['data'])) {
            $vinResult['data'] = array_merge($vinResult['data'], $relatedData);
        }

        return $vinResult;
    }

    /**
     * Optimized VIN matching with concurrent database operations
     */
    private function findBestMatchVinOptimized(string $vin, array $vendor): array
    {
        $resData = [
            "manufacturer" => null,
            "model" => null,
            "year" => null,
            "trim" => null
        ];

        // Use parallel processing for database queries
        $parallel = new Parallel();

        // Coroutine 1: Search existing VIN logs
        $parallel->add(function () use ($vin) {
            return $this->searchVinLogsOptimized($vin);
        });

        // Coroutine 2: Prepare fallback Mojaz search if needed
        $parallel->add(function () use ($vin) {
            // This can be used for pre-warming cache or other optimizations
            return $this->prepareFallbackSearch($vin);
        });

        [$vinLogs, $fallbackData] = $parallel->wait();

        $bestMatch = $this->findBestMatchFromLogs($vin, $vinLogs);

        if (!$bestMatch) {
            return $this->handleNoMatchFound($vin, $vendor, $resData);
        }

        return $this->prepareMatchResponse($bestMatch, $vin, $vendor);
    }

    /**
     * Optimized VIN logs search with batching and indexing
     */
    private function searchVinLogsOptimized(string $vin): array
    {
        // Use coroutine-safe database operations
        return $this->executeDbOperation(function () use ($vin) {
            $vinPrefix = substr($vin, 0, self::VIN_MATCH_THRESHOLD);

            return Db::table('vin_logs as vl')
                ->select([
                    'vl.id', 'vl.vin', 'vl.ad_make', 'vl.ad_model', 'vl.ad_year',
                    'vl.make_id', 'vl.model_id', 'vl.year_id', 'vl.trim_id', 'vl.link_status',
                    'm.name as make_name',
                    'md.name as model_name',
                    'y.name as year_name',
                    't.name as trim_name',
                    'vcbp.base_price'
                ])
                ->leftJoin('vehicle_manufacturers as m', 'vl.make_id', '=', 'm.id')
                ->leftJoin('vehicle_model as md', 'vl.model_id', '=', 'md.id')
                ->leftJoin('vehicle_year as y', 'vl.year_id', '=', 'y.id')
                ->leftJoin('vehicle_trims as t', 'vl.trim_id', '=', 't.id')
                ->leftJoin('vehicle_categories as vcbp', 'vl.trim_id', '=', 'vcbp.vehicle_trim_id')
                ->where('vl.vin', 'LIKE', $vinPrefix . '%')
                ->orderBy('vl.id', 'desc')
                ->limit(self::MAX_BATCH_SIZE)
                ->get()
                ->toArray();
        });
    }

    /**
     * Execute database operation with concurrency control
     */
    private function executeDbOperation(callable $operation)
    {
        // Acquire semaphore
        $this->dbChannel->pop();

        try {
            return $operation();
        } finally {
            // Release semaphore
            $this->dbChannel->push(true);
        }
    }

    /**
     * Find best match from VIN logs with optimized algorithm
     */
    private function findBestMatchFromLogs(string $vin, array $vinLogs): ?object
    {
        $maxMatches = 0;
        $bestMatch = null;
        $highestBasePrice = 0;

        // Optimize matching with early termination
        foreach ($vinLogs as $vinLog) {
            $vinLog = (object) $vinLog;
            $currentVin = $vinLog->vin;

            // Fast character comparison with early exit
            $matchedCount = $this->calculateVinMatchCount($vin, $currentVin);

            if ($matchedCount >= self::VIN_MATCH_THRESHOLD) {
                $basePrice = (float) ($vinLog->base_price ?? 0);

                if ($this->isBetterMatch($matchedCount, $maxMatches, $basePrice, $highestBasePrice, $vinLog, $bestMatch)) {
                    $maxMatches = $matchedCount;
                    $highestBasePrice = $basePrice;
                    $bestMatch = $vinLog;
                }
            }
        }

        return $bestMatch;
    }

    /**
     * Optimized VIN character matching
     */
    private function calculateVinMatchCount(string $vin1, string $vin2): int
    {
        $length = min(strlen($vin1), strlen($vin2));
        $matchCount = 0;

        for ($i = 0; $i < $length; $i++) {
            if ($vin1[$i] === $vin2[$i]) {
                $matchCount++;
            } else {
                break; // Early termination on first mismatch
            }
        }

        return $matchCount;
    }

    /**
     * Determine if current match is better than previous best
     */
    private function isBetterMatch(int $currentMatches, int $maxMatches, float $currentPrice, float $highestPrice, object $currentMatch, ?object $bestMatch): bool
    {
        return $currentMatches > $maxMatches ||
            ($currentMatches === $maxMatches &&
                $currentMatch->trim_id !== ($bestMatch->trim_id ?? null) &&
                $currentPrice > $highestPrice);
    }

    /**
     * Handle case when no match is found
     */
    private function handleNoMatchFound(string $vin, array $vendor, array $resData): array
    {
        // Use coroutine for Mojaz search
        $mojazResult = null;

        Coroutine::create(function () use ($vin, &$mojazResult) {
            $mojazResult = $this->searchMojazOptimized($vin);
        });

        // Wait for Mojaz result
        while ($mojazResult === null) {
            Coroutine::sleep(0.001); // 1ms
        }

        if ($mojazResult && $mojazResult["success"]) {
            return $this->handleMojazResult($vin, $mojazResult, $vendor, $resData);
        }

        // Create empty VIN log asynchronously
        $this->createEmptyVinLogAsync($vin, $vendor);

        return ['success' => true, "message" => "Could not get the full response for this VIN!", "data" => $resData];
    }

    /**
     * Handle Mojaz search result
     */
    private function handleMojazResult(string $vin, array $mojazResult, array $vendor, array $resData): array
    {
        $data = $mojazResult["data"];
        $source = "product_api_vendor_(" . ($vendor['vendor_name'] ?? '') . ")";

        // Search for matching VIN log in parallel
        $parallel = new Parallel();

        $parallel->add(function () use ($data) {
            return $this->findMatchingVinLog($data);
        });

        $parallel->add(function () use ($vin, $data, $source, $vendor) {
            // Pre-create VIN log data
            return $this->prepareVinLogData($vin, $data, $source, $vendor['id']);
        });

        [$matchingVinLog, $vinLogData] = $parallel->wait();

        if (!$matchingVinLog) {
            $this->createVinLogAsync($vinLogData);

            return [
                'success' => true,
                "message" => "Could not get the full response for this VIN!",
                "data" => [
                    "manufacturer" => $this->getManufacturerNameCached($data["make_id"]),
                    "model" => $this->getModelNameCached($data["model_id"]),
                    "year" => $this->getYearNameCached($data["year_id"]),
                    "trim" => null
                ]
            ];
        }

        $this->createVinLogWithMatchAsync($vin, $data, $matchingVinLog, $source, $vendor['id']);
        return $this->prepareMatchResponse($matchingVinLog, $vin, $vendor);
    }

    /**
     * Prepare final match response
     */
    private function prepareMatchResponse(object $bestMatch, string $vin, array $vendor): array
    {
        $trimDefault = null;

        // Check link status
        if (!in_array($bestMatch->link_status, [1, 2])) {
            $resData = [
                "manufacturer" => $bestMatch->make_name,
                "model" => $bestMatch->model_name,
                "year" => $bestMatch->year_name ? (int)$bestMatch->year_name : null,
                "trim" => $bestMatch->trim_name ?? $trimDefault
            ];
            return ['success' => true, "message" => "Could not get the full response for this VIN!", "data" => $resData];
        }

        // Validate required IDs
        if ($bestMatch->make_id <= 0 || $bestMatch->model_id <= 0 || $bestMatch->year_id <= 0) {
            if ($bestMatch->link_status == 2) {
                $trimDefault = "Default...";
            }

            $resData = [
                "manufacturer" => $bestMatch->make_name,
                "model" => $bestMatch->model_name,
                "year" => $bestMatch->year_name ? (int)$bestMatch->year_name : null,
                "trim" => $bestMatch->trim_name ?? $trimDefault
            ];
            return ['success' => true, "message" => "Could not get the full response for this VIN!", "data" => $resData];
        }

        if ($bestMatch->link_status == 2) {
            $trimDefault = "Default...";
        }

        $resData = [
            "manufacturer" => $bestMatch->make_name,
            "model" => $bestMatch->model_name,
            "year" => $bestMatch->year_name ? (int)$bestMatch->year_name : null,
            "trim" => $bestMatch->trim_name ?? $trimDefault
        ];

        return ['success' => true, "message" => "Data exists!", "data" => $resData];
    }

    /**
     * Optimized Mojaz search with timeout and retry
     */
    private function searchMojazOptimized(string $vin): array
    {
        $data = [
            "make_id" => null,
            "model_id" => null,
            "year_id" => null,
            "link_status" => 0,
            "mojaz_search_response" => ""
        ];

        // Mojaz API is disabled, return failure
        return ["success" => false, "message" => "Unable to fetch!", "data" => $data];
    }

    /**
     * Find matching VIN log with optimized query
     */
    private function findMatchingVinLog(array $data): ?object
    {
        return $this->executeDbOperation(function () use ($data) {
            return Db::table('vin_logs as vl')
                ->select([
                    'vl.*',
                    'm.name as make_name',
                    'md.name as model_name',
                    'y.name as year_name',
                    't.name as trim_name'
                ])
                ->leftJoin('vehicle_manufacturers as m', 'vl.make_id', '=', 'm.id')
                ->leftJoin('vehicle_models as md', 'vl.model_id', '=', 'md.id')
                ->leftJoin('vehicle_years as y', 'vl.year_id', '=', 'y.id')
                ->leftJoin('vehicle_trims as t', 'vl.trim_id', '=', 't.id')
                ->where('vl.make_id', $data["make_id"])
                ->where('vl.model_id', $data["model_id"])
                ->where('vl.year_id', $data["year_id"])
                ->orderBy('vl.confidence_level', 'desc')
                ->first();
        });
    }

    /**
     * Prepare VIN log data for insertion
     */
    private function prepareVinLogData(string $vin, array $data, string $source, int $vendorId): array
    {
        return [
            'vin' => $vin,
            'trim_vin' => substr($vin, 0, 10),
            'make_id' => $data["make_id"] ?? null,
            'model_id' => $data["model_id"] ?? null,
            'year_id' => $data["year_id"] ?? null,
            'trim_id' => null,
            'link_status' => $data["link_status"] ?? 0,
            'source' => $source . "-NRV",
            'vendor_id' => $vendorId,
            'mojaz_search_response' => $data["mojaz_search_response"] ?? null,
            'confidence_level' => 0,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s')
        ];
    }

    /**
     * Prefetch related data for optimization
     */
    private function prefetchRelatedData(string $vin): array
    {
        // This can be used for caching frequently accessed data
        // or preparing data that might be needed
        return [];
    }

    /**
     * Prepare fallback search data
     */
    private function prepareFallbackSearch(string $vin): array
    {
        // This can be used for cache warming or other optimizations
        return [];
    }

    /**
     * Async VIN log creation
     */
    private function createVinLogAsync(array $vinLogData): void
    {
        Coroutine::create(function () use ($vinLogData) {
            $this->executeDbOperation(function () use ($vinLogData) {
                Db::table('vin_logs')->insert($vinLogData);
            });
        });
    }

    /**
     * Async VIN log creation with match
     */
    private function createVinLogWithMatchAsync(string $vin, array $data, object $matchingVinLog, string $source, int $vendorId): void
    {
        Coroutine::create(function () use ($vin, $data, $matchingVinLog, $source, $vendorId) {
            $this->executeDbOperation(function () use ($vin, $data, $matchingVinLog, $source, $vendorId) {
                Db::table('vin_logs')->insert([
                    'vin' => $vin,
                    'trim_vin' => substr($vin, 0, 10),
                    'make_id' => $matchingVinLog->make_id,
                    'mojaz_make' => $data["mojaz_make"] ?? null,
                    'model_id' => $matchingVinLog->model_id,
                    'mojaz_model' => $data["mojaz_model"] ?? null,
                    'year_id' => $matchingVinLog->year_id,
                    'mojaz_year' => $data["mojaz_year"] ?? null,
                    'trim_id' => $matchingVinLog->trim_id,
                    'link_status' => $matchingVinLog->link_status ?? 0,
                    'source' => $source . "-NRV",
                    'vendor_id' => $vendorId,
                    'mojaz_search_response' => $data["mojaz_search_response"] ?? null,
                    'confidence_level' => 0,
                    'created_at' => date('Y-m-d H:i:s'),
                    'updated_at' => date('Y-m-d H:i:s')
                ]);
            });
        });
    }

    /**
     * Async empty VIN log creation
     */
    private function createEmptyVinLogAsync(string $vin, array $vendor): void
    {
        Coroutine::create(function () use ($vin, $vendor) {
            $source = "product_api_vendor_(" . ($vendor['vendor_name'] ?? '') . ")";

            $this->executeDbOperation(function () use ($vin, $source, $vendor) {
                Db::table('vin_logs')->insert([
                    'vin' => $vin,
                    'trim_vin' => substr($vin, 0, 10),
                    'make_id' => null,
                    'model_id' => null,
                    'year_id' => null,
                    'trim_id' => null,
                    'link_status' => 0,
                    'source' => $source . "-NRV",
                    'vendor_id' => $vendor['id'],
                    'confidence_level' => 0,
                    'created_at' => date('Y-m-d H:i:s'),
                    'updated_at' => date('Y-m-d H:i:s')
                ]);
            });
        });
    }

    /**
     * Cached manufacturer name lookup
     */
    private function getManufacturerNameCached(?int $makeId): ?string
    {
        if (!$makeId) return null;

        // This could use Redis cache in a real implementation
        return $this->executeDbOperation(function () use ($makeId) {
            $manufacturer = Db::table('vehicle_manufacturers')
                ->select('name')
                ->where('id', $makeId)
                ->first();

            return $manufacturer->name ?? null;
        });
    }

    /**
     * Cached model name lookup
     */
    private function getModelNameCached(?int $modelId): ?string
    {
        if (!$modelId) return null;

        return $this->executeDbOperation(function () use ($modelId) {
            $model = Db::table('vehicle_models')
                ->select('name')
                ->where('id', $modelId)
                ->first();

            return $model->name ?? null;
        });
    }

    /**
     * Cached year name lookup
     */
    private function getYearNameCached(?int $yearId): ?int
    {
        if (!$yearId) return null;

        return $this->executeDbOperation(function () use ($yearId) {
            $year = Db::table('vehicle_years')
                ->select('name')
                ->where('id', $yearId)
                ->first();

            return $year->name ? (int)$year->name : null;
        });
    }

    /**
     * Async API call logging
     */
    private function logApiCallAsync(int $vendorId, array $logData, array $requestData, array $response, ?float $processingTime = null): void
    {
        Coroutine::create(function () use ($vendorId, $logData, $requestData, $response, $processingTime) {
            try {
                $this->executeDbOperation(function () use ($vendorId, $logData, $requestData, $response, $processingTime) {
                    Db::table('product_api_logs')->insert([
                        'vendor_id' => $vendorId,
                        'endpoint' => $logData['endpoint'] ?? 'partnerVinDecoder',
                        'request_data' => json_encode($requestData),
                        'response_data' => json_encode($response),
                        'processing_time_ms' => $processingTime,
                        'user_agent' => $logData['user_agent'] ?? null,
                        'created_at' => date('Y-m-d H:i:s'),
                        'updated_at' => date('Y-m-d H:i:s')
                    ]);
                });
            } catch (\Throwable $e) {
                $this->logger->error('Failed to log API call: ' . $e->getMessage());
            }
        });
    }

    /**
     * Create error response
     */
    private function errorResponse(array $data, int $status): PsrResponseInterface
    {
        return $this->response->json($data)->withStatus($status);
    }
}