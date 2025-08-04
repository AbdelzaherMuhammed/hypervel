<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Hyperf\Context\Context;
use Hyperf\Di\Annotation\Inject;
use Hyperf\HttpServer\Annotation\Middleware;
use Hyperf\Logger\LoggerFactory;
use Hyperf\Redis\RedisFactory;
use Hyperf\HttpServer\Contract\ResponseInterface;
use Hyperf\Context\ApplicationContext;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface as PsrResponseInterface;
use Psr\Log\LoggerInterface;
use Carbon\Carbon;

class CheckUsageMiddleware extends Middleware
{
    #[Inject]
    protected RedisFactory $redisFactory;

    private ?ResponseInterface $response = null; // Made nullable with default
    private ContainerInterface $container; // Store container reference

    protected $redis;
    protected LoggerInterface $logger;

    private const RATE_LIMITED_KEYS = ['5MBrtEmfFErSikLP'];
    private const RATE_LIMITED_VENDORS = [28 => 40000];

    public function __construct(ContainerInterface $container)
    {
        $this->container = $container;
        $this->redisFactory = $container->get(RedisFactory::class);
        $this->redis = $this->redisFactory->get('default');
        $this->logger = $container->get(LoggerFactory::class)->get('usage', 'default');

        // Safe initialization with fallback
        try {
            $this->response = $container->get(ResponseInterface::class);
        } catch (\Throwable $e) {
            $this->logger->warning('Failed to inject ResponseInterface in CheckUsageMiddleware: ' . $e->getMessage());
            // Will be lazily loaded in errorResponse method
        }
    }

    public function handle(ServerRequestInterface $request, Closure $next): PsrResponseInterface
    {
        try {
            $startTime = microtime(true);
            Context::set('request.start_time', $startTime);

            // Get authenticated vendor from context (set by CheckApiKey middleware)
            $vendor = Context::get('authenticated.vendor');

            if (!$vendor) {
                return $this->errorResponse([
                    "success" => false,
                    "message" => "Authentication required",
                    "code" => "ct-0001"
                ], 401);
            }

            $apiKey = $this->extractApiKey($request);

            // Check if this API key needs rate limiting (matches your Laravel logic)
            if (in_array($apiKey, self::RATE_LIMITED_KEYS)) {
                if (in_array($vendor['id'], array_keys(self::RATE_LIMITED_VENDORS))) {
                    $dailyLimit = self::RATE_LIMITED_VENDORS[$vendor['id']];

                    // Create cache key for today (Riyadh timezone - matches your Laravel code)
                    $now = Carbon::now('Asia/Riyadh');
                    $cacheKey = "api_limit:{$apiKey}:" . $now->toDateString();

                    // Get current API call count
                    $apiCalls = (int) $this->redis->get($cacheKey);

                    if ($apiCalls >= $dailyLimit) {
                        return $this->errorResponse([
                            "success" => false,
                            "message" => "You have reached your daily limit. Please contact your Account Manager for further assistance.",
                            "code" => "ct-0003"
                        ], 429);
                    }

                    // Increment the API call count
                    $this->redis->incr($cacheKey);

                    // Set expiration to end of day
                    $secondsUntilEndOfDay = $now->copy()->endOfDay()->diffInSeconds($now);
                    $this->redis->expire($cacheKey, $secondsUntilEndOfDay);
                }
            }

            // Process the request
            $response = $next($request);

            // Calculate processing time and store in context
            $processingTime = round((microtime(true) - $startTime) * 1000, 2);
            Context::set('request.processing_time', $processingTime);

            return $response;

        } catch (\Throwable $e) {
            $this->logger->error('Usage middleware error: ' . $e->getTraceAsString(), [
                'vendor_id' => $vendor['id'] ?? null
            ]);

            return $this->errorResponse([
                "success" => false,
                "message" => $e->getTraceAsString(),
                "code" => "ct-0500"
            ], 500);
        }
    }

    private function extractApiKey(ServerRequestInterface $request): ?string
    {
        $headers = $request->getHeaders();
        return $headers['x-api-key'][0] ?? null;
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
                // Fallback 3: Log error and re-throw
                $this->logger->error('All response fallbacks failed in CheckUsageMiddleware: ' . $e2->getMessage());
                throw new \RuntimeException('Unable to create error response: ' . $e2->getMessage());
            }
        }
    }
}