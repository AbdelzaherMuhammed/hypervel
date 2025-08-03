<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Hyperf\Context\Context;
use Hyperf\Di\Annotation\Inject;
use Hyperf\HttpServer\Annotation\Middleware;
use Hyperf\Logger\LoggerFactory;
use Hyperf\Redis\RedisFactory;
use Hyperf\DbConnection\Db;
use Hyperf\Coroutine\Coroutine;
use Hyperf\HttpServer\Contract\ResponseInterface;
use Hyperf\HttpServer\Contract\RequestInterface;
use Hyperf\Coroutine\Parallel;
use Hyperf\Context\ApplicationContext;
use Hypervel\Coroutine\Channel;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface as PsrResponseInterface;
use Psr\Log\LoggerInterface;

class CheckApiKey extends Middleware
{
    private $redis;
    private LoggerInterface $logger;
    private ?ResponseInterface $response = null; // Made nullable with default
    private ContainerInterface $container; // Store container reference
    private Channel $dbChannel;
    private const MAX_CONCURRENT_DB_OPS = 5;
    private const VENDOR_CACHE_TTL = 3600; // 1 hour
    private const PERMISSION_CACHE_TTL = 86400; // 24 hours
    private const FAILED_ATTEMPTS_TTL = 3600; // 1 hour
    private const METRICS_TTL = 604800; // 7 days

    public function __construct(ContainerInterface $container)
    {
        $this->container = $container;
        $this->redis = $container->get(RedisFactory::class)->get('default');
        $this->logger = $container->get(LoggerFactory::class)->get('auth', 'default');

        // Safe initialization with fallback
        try {
            $this->response = $container->get(ResponseInterface::class);
        } catch (\Throwable $e) {
            $this->logger->warning('Failed to inject ResponseInterface: ' . $e->getMessage());
            // Will be lazily loaded in errorResponse method
        }

        // Initialize concurrency control
        $this->dbChannel = new Channel(self::MAX_CONCURRENT_DB_OPS);
        for ($i = 0; $i < self::MAX_CONCURRENT_DB_OPS; $i++) {
            $this->dbChannel->push(true);
        }
    }

    /**
     * Process an incoming server request with optimized concurrency
     */
    public function handle(ServerRequestInterface $request, Closure $next): PsrResponseInterface
    {
        try {
            $startTime = microtime(true);
            Context::set('request.start_time', $startTime);

            // Step 1: Fast validation - extract API key
            $apiKey = $this->extractApiKey($request);
            if (!$apiKey) {
                $this->logFailedAttemptAsync($request, null, "ct-0001", "Missing API key");
                return $this->errorResponse([
                    "success" => false,
                    "message" => "Authentication key(x-api-key) not present in header!",
                    "code" => "ct-0001"
                ], 401);
            }

            $parallel = new Parallel();

            // Coroutine 1: Get vendor by token (with caching)
            $parallel->add(function () use ($apiKey) {
                return $this->getVendorByTokenOptimized($apiKey);
            });

            // Coroutine 2: Prepare log data (non-blocking)
            $parallel->add(function () use ($request) {
                return $this->prepareLogDataOptimized($request);
            });

            // Coroutine 3: Pre-warm cache for common lookups
            $parallel->add(function () use ($apiKey) {
                return $this->preWarmCacheData($apiKey);
            });

            [$vendor, $logData, $cacheWarmed] = $parallel->wait();

            // Step 3: Fast vendor validation
            if (!$vendor) {
                $this->logFailedAttemptAsync($request, null, "ct-0002", "Invalid API key", $logData);
                return $this->errorResponse([
                    "success" => false,
                    "message" => "Invalid authentication key(x-api-key) provided!",
                    "code" => "ct-0002"
                ], 401);
            }

            // Step 4: Concurrent permission check and context setup
            $endpoint = $this->getEndpoint($request);

            $parallel = new Parallel();

            // Coroutine 1: Check vendor permissions
            $parallel->add(function () use ($vendor, $endpoint) {
                return $this->checkVendorPermissionsOptimized($vendor['id'], $endpoint);
            });

            // Coroutine 2: Update vendor metrics (fire and forget style)
            $parallel->add(function () use ($vendor, $logData) {
                return $this->updateVendorMetricsAsync($vendor, $logData);
            });

            [$hasPermission, $metricsUpdated] = $parallel->wait();

            if (!$hasPermission) {
                $this->logFailedAttemptAsync($request, $vendor['id'], "ct-0003", "Insufficient permissions", $logData);
                return $this->errorResponse([
                    "success" => false,
                    "message" => "Unauthorized! Contact Management.",
                    "code" => "ct-0003"
                ], 401);
            }

            // Step 5: Set context and continue (minimal blocking)
            Context::set('authenticated.vendor', $vendor);
            Context::set('api.log_data', $logData);

            // Fire and forget success logging
            $this->logSuccessfulAuthAsync($vendor, $apiKey, $request, $logData);

            return $next($request);

        } catch (\Throwable $e) {
            $this->logger->error('Auth middleware error: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
                'api_key' => substr($apiKey ?? '', 0, 8) . '...'
            ]);

            return $this->errorResponse([
                "success" => false,
                "message" => $e->getMessage(),
                "code" => "ct-0500"
            ], 500);
        }
    }

    /**
     * Extract API key from request headers (optimized)
     */
    private function extractApiKey(ServerRequestInterface $request): ?string
    {
        // Direct header access for better performance
        $headers = $request->getHeaders();
        $apiKey = $headers['x-api-key'][0] ?? $headers['X-API-KEY'][0] ?? null;

        return $apiKey ? trim($apiKey) : null;
    }

    /**
     * Get endpoint from request path (optimized)
     */
    private function getEndpoint(ServerRequestInterface $request): string
    {
        static $endpointCache = [];

        $path = $request->getUri()->getPath();

        if (isset($endpointCache[$path])) {
            return $endpointCache[$path];
        }

        $segments = explode('/', trim($path, '/'));
        $endpoint = end($segments) ?: 'unknown';

        // Cache for this request cycle
        $endpointCache[$path] = $endpoint;

        return $endpoint;
    }

    /**
     * Optimized vendor lookup with multi-level caching
     */
    private function getVendorByTokenOptimized(string $token): ?array
    {
        // Level 1: Memory cache (fastest)
        static $memoryCache = [];
        $memoryKey = hash('crc32', $token);

        if (isset($memoryCache[$memoryKey])) {
            return $memoryCache[$memoryKey];
        }

        // Level 2: Redis cache
        $cacheKey = "vendor:token:" . hash('sha256', $token);

        try {
            $cached = $this->redis->get($cacheKey);
            if ($cached) {
                $vendor = json_decode($cached, true);
                $memoryCache[$memoryKey] = $vendor;
                return $vendor;
            }
        } catch (\Throwable $e) {
            $this->logger->warning('Redis cache read failed: ' . $e->getMessage());
        }

        // Level 3: Database lookup with concurrency control
        $vendor = $this->executeDbOperation(function () use ($token) {
            return Db::table('price_guide_vendors')
                ->select(['id', 'vendor_name', 'pe_token', 'status', 'permissions'])
                ->where('pe_token', $token)
                ->where('status', 1)
                ->first();
        });

        if ($vendor) {
            $vendorArray = (array) $vendor;

            // Update caches asynchronously
            $this->updateVendorCacheAsync($cacheKey, $vendorArray, $memoryKey);

            return $vendorArray;
        }

        return null;
    }

    /**
     * Update vendor cache asynchronously
     */
    private function updateVendorCacheAsync(string $cacheKey, array $vendorArray, string $memoryKey): void
    {
        Coroutine::create(function () use ($cacheKey, $vendorArray, $memoryKey) {
            try {
                // Update Redis cache
                $this->redis->setex($cacheKey, self::VENDOR_CACHE_TTL, json_encode($vendorArray));

                // Update memory cache
                static $memoryCache = [];
                $memoryCache[$memoryKey] = $vendorArray;
            } catch (\Throwable $e) {
                $this->logger->warning('Failed to update vendor cache: ' . $e->getMessage());
            }
        });
    }

    /**
     * Optimized permission checking with caching
     */
    private function checkVendorPermissionsOptimized(int $vendorId, string $endpoint): bool
    {
        $permissionMap = [
            'partnerVinDecoder' => 'vin-decoder',
            'partnerPriceGuide' => 'new-car-price',
            'partnerUsedCarPrice' => 'used-car-price',
            'partnerUsedCarPriceWithSpecs' => 'used-car-price-with-specs',
            'partnerVehicleFutureResidualValue' => 'vehicle-future-residual-value',
            'partnerUsedCarPriceWithSpecsSafety' => 'used-car-price-with-specs-safety',
            'partnerUsedCarPriceWithSpecsSafetyReliability' => 'used-car-price-with-specs-safety-reliability',
        ];

        $requiredPermission = $permissionMap[$endpoint] ?? $endpoint;

        // Fast cache lookup
        $cacheKey = "vendor:permissions:{$vendorId}";

        try {
            $cached = $this->redis->get($cacheKey);
            if ($cached) {
                $permissions = json_decode($cached, true);
                return isset($permissions[$requiredPermission]) && $permissions[$requiredPermission] === true;
            }
        } catch (\Throwable $e) {
            $this->logger->warning('Permission cache read failed: ' . $e->getMessage());
        }

        // Database lookup with concurrency control
        $vendor = $this->executeDbOperation(function () use ($vendorId) {
            return Db::table('price_guide_vendors')
                ->select(['permissions'])
                ->where('id', $vendorId)
                ->where('status', 1)
                ->first();
        });

        if (!$vendor || !$vendor->permissions) {
            return false;
        }

        $permissions = json_decode($vendor->permissions, true);

        if (!is_array($permissions)) {
            $this->logger->warning("Invalid permissions JSON for vendor {$vendorId}");
            return false;
        }

        // Update cache asynchronously
        $this->updatePermissionCacheAsync($cacheKey, $permissions);

        return isset($permissions[$requiredPermission]) && $permissions[$requiredPermission] === true;
    }

    /**
     * Update permission cache asynchronously
     */
    private function updatePermissionCacheAsync(string $cacheKey, array $permissions): void
    {
        Coroutine::create(function () use ($cacheKey, $permissions) {
            try {
                $this->redis->setex($cacheKey, self::PERMISSION_CACHE_TTL, json_encode($permissions));
            } catch (\Throwable $e) {
                $this->logger->warning('Failed to update permission cache: ' . $e->getMessage());
            }
        });
    }

    /**
     * Optimized log data preparation
     */
    private function prepareLogDataOptimized(ServerRequestInterface $request): array
    {
        return [
            'endpoint' => $this->getEndpoint($request),
            'request_data' => json_encode($this->sanitizeRequestDataOptimized($request)),
            'user_agent' => $request->getHeaderLine('User-Agent'),
            'ip_address' => $this->getClientIpOptimized($request),
            'created_at' => date('Y-m-d H:i:s'),
        ];
    }

    /**
     * Pre-warm cache data for common operations
     */
    private function preWarmCacheData(string $apiKey): bool
    {
        // This could pre-load frequently accessed data
        // For now, it's a placeholder for future optimizations
        return true;
    }

    /**
     * Update vendor metrics asynchronously
     */
    private function updateVendorMetricsAsync(array $vendor, array $logData): bool
    {
        Coroutine::create(function () use ($vendor, $logData) {
            try {
                $parallel = new Parallel();

                // Update success metrics
                $parallel->add(function () use ($vendor) {
                    $successKey = "auth_success:" . date('Y-m-d');
                    $this->redis->hincrby($successKey, "vendor:{$vendor['id']}", 1);
                    $this->redis->expire($successKey, self::METRICS_TTL);
                    return true;
                });

                // Update API usage metrics
                $parallel->add(function () use ($vendor, $logData) {
                    $usageKey = "api_usage:{$vendor['id']}:" . date('Y-m-d-H');
                    $this->redis->hincrby($usageKey, $logData['endpoint'], 1);
                    $this->redis->expire($usageKey, self::METRICS_TTL);
                    return true;
                });

                $parallel->wait();
            } catch (\Throwable $e) {
                $this->logger->warning('Failed to update vendor metrics: ' . $e->getMessage());
            }
        });

        return true;
    }

    /**
     * Database operation with concurrency control
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
     * Async failed attempt logging
     */
    private function logFailedAttemptAsync(ServerRequestInterface $request, ?int $vendorId, string $code, string $reason, ?array $logData = null): void
    {
        Coroutine::create(function () use ($request, $vendorId, $code, $reason, $logData) {
            try {
                if (!$logData) {
                    $logData = $this->prepareLogDataOptimized($request);
                }

                $response = ["success" => false, "code" => $code, "message" => $reason];

                $parallel = new Parallel();

                // Log to database
                $parallel->add(function () use ($logData, $vendorId, $response) {
                    return $this->executeDbOperation(function () use ($logData, $vendorId, $response) {
                        return Db::table('product_api_logs')->insert([
                            'vendor_id' => $vendorId,
                            'endpoint' => $logData['endpoint'],
                            'request_data' => $logData['request_data'],
                            'response_data' => json_encode($response),
                            'created_at' => $logData['created_at'],
                            'updated_at' => $logData['created_at'],
                        ]);
                    });
                });

                // Log to Redis for monitoring
                $parallel->add(function () use ($logData) {
                    $failedAttemptsKey = "failed_auth:" . date('Y-m-d-H');
                    $this->redis->hincrby($failedAttemptsKey, $logData['ip_address'], 1);
                    $this->redis->expire($failedAttemptsKey, self::FAILED_ATTEMPTS_TTL);
                    return true;
                });

                $parallel->wait();

            } catch (\Throwable $e) {
                $this->logger->error('Failed to log auth attempt: ' . $e->getMessage());
            }
        });
    }

    /**
     * Async successful authentication logging
     */
    private function logSuccessfulAuthAsync(array $vendor, string $apiKey, ServerRequestInterface $request, array $logData): void
    {
        Coroutine::create(function () use ($vendor, $apiKey, $request, $logData) {
            try {
                $this->logger->info('Successful API authentication', [
                    'vendor_id' => $vendor['id'],
                    'vendor_name' => $vendor['vendor_name'],
                    'endpoint' => $logData['endpoint'],
                    'ip' => $logData['ip_address']
                ]);
            } catch (\Throwable $e) {
                $this->logger->error('Failed to log successful auth: ' . $e->getMessage());
            }
        });
    }

    /**
     * Create error response with robust fallback mechanism
     */
    private function errorResponse(array $data, int $status): PsrResponseInterface
    {
        try {
            // Try to use the injected response first
            if ($this->response !== null) {
                return $this->response->json($data)->withStatus($status);
            }

            // Fallback 1: Try to get from container
            $response = $this->container->get(ResponseInterface::class);
            return $response->json($data)->withStatus($status);

        } catch (\Throwable $e) {
            try {
                // Fallback 2: Use ApplicationContext
                $response = ApplicationContext::getContainer()->get(ResponseInterface::class);
                return $response->json($data)->withStatus($status);
            } catch (\Throwable $e2) {
                // Fallback 3: Create a basic PSR-7 response
                $this->logger->error('All response fallbacks failed: ' . $e2->getMessage());

                // This would need a PSR-7 response factory implementation
                // You might need to implement this based on your framework setup
                throw new \RuntimeException('Unable to create error response: ' . $e2->getMessage());
            }
        }
    }

    /**
     * Optimized request data sanitization
     */
    private function sanitizeRequestDataOptimized(ServerRequestInterface $request): array
    {
        static $sensitiveFields = ['password', 'token', 'secret', 'key', 'auth'];

        $data = $request->getParsedBody() ?? [];
        $queryParams = $request->getQueryParams();
        $allData = array_merge($data, $queryParams);

        // Fast sanitization
        foreach ($sensitiveFields as $field) {
            if (isset($allData[$field])) {
                $allData[$field] = '[REDACTED]';
            }
        }

        return $allData;
    }

    /**
     * Optimized client IP detection
     */
    private function getClientIpOptimized(ServerRequestInterface $request): string
    {
        static $ipCache = [];
        $requestId = spl_object_hash($request);

        if (isset($ipCache[$requestId])) {
            return $ipCache[$requestId];
        }

        $serverParams = $request->getServerParams();
        $ipHeaders = [
            'HTTP_CF_CONNECTING_IP',
            'HTTP_X_REAL_IP',
            'HTTP_X_FORWARDED_FOR',
            'REMOTE_ADDR'
        ];

        foreach ($ipHeaders as $header) {
            if (!empty($serverParams[$header])) {
                $ip = $serverParams[$header];

                // Handle comma-separated IPs
                if (strpos($ip, ',') !== false) {
                    $ip = trim(explode(',', $ip)[0]);
                }

                // Validate IP
                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                    $ipCache[$requestId] = $ip;
                    return $ip;
                }
            }
        }

        $fallbackIp = $serverParams['REMOTE_ADDR'] ?? 'unknown';
        $ipCache[$requestId] = $fallbackIp;

        return $fallbackIp;
    }
}