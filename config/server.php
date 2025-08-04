<?php

declare(strict_types=1);

use App\Http\Kernel as HttpKernel;
use Hyperf\Server\Event;
use Hyperf\Server\Server;
use Swoole\Constant;

return [
    'mode' => SWOOLE_PROCESS,
    'servers' => [
        [
            'name' => 'http',
            'type' => Server::SERVER_HTTP,
            'host' => env('HTTP_SERVER_HOST', '0.0.0.0'),
            'port' => (int) env('HTTP_SERVER_PORT', 9501),
            'sock_type' => SWOOLE_SOCK_TCP,
            'callbacks' => [
                Event::ON_REQUEST => [HttpKernel::class, 'onRequest'],
            ],
        ],
    ],
    'kernels' => [
        'http' => HttpKernel::class,
    ],
    'settings' => [
        'document_root' => base_path('public'),
        'enable_static_handler' => true,
        Constant::OPTION_ENABLE_COROUTINE => true,

        // CRITICAL: Much more conservative worker count
        Constant::OPTION_WORKER_NUM => min(env('SERVER_WORKERS_NUMBER', 4), 8),

        Constant::OPTION_PID_FILE => base_path('runtime/hypervel.pid'),
        Constant::OPTION_OPEN_TCP_NODELAY => true,

        // CRITICAL: Drastically reduce max coroutines
        Constant::OPTION_MAX_COROUTINE => env('SERVER_MAX_COROUTINE', 1000),

        Constant::OPTION_OPEN_HTTP2_PROTOCOL => false, // Disable HTTP2 to reduce complexity

        // More aggressive request limits
        Constant::OPTION_MAX_REQUEST => env('SERVER_MAX_REQUEST', 1000),

        // Smaller buffer sizes
        Constant::OPTION_SOCKET_BUFFER_SIZE => 128 * 1024, // 128KB
        Constant::OPTION_BUFFER_OUTPUT_SIZE => 128 * 1024,

        // CRITICAL: Very conservative connection limits
        Constant::OPTION_BACKLOG => 64,
        Constant::OPTION_MAX_CONN => env('SERVER_MAX_CONNECTIONS', 512), // Much lower

        // Aggressive heartbeat detection
        Constant::OPTION_HEARTBEAT_CHECK_INTERVAL => 10, // Check every 10 seconds
        Constant::OPTION_HEARTBEAT_IDLE_TIME => 60, // Kill idle connections after 1 minute

        // Enable proper connection closing
        Constant::OPTION_OPEN_HTTP_PROTOCOL => true,
        Constant::OPTION_OPEN_MQTT_PROTOCOL => false,

        // Very aggressive timeouts
        Constant::OPTION_SOCKET_RECV_TIMEOUT => 1,
        Constant::OPTION_SOCKET_SEND_TIMEOUT => 1,

        // CRITICAL: Enable connection reuse and proper TCP settings
        Constant::OPTION_ENABLE_REUSE_PORT => true,
        Constant::OPTION_TCP_KEEPIDLE => 60, // Much shorter
        Constant::OPTION_TCP_KEEPCOUNT => 3, // Fewer retries

        // Prevent connection accumulation
        Constant::OPTION_TCP_DEFER_ACCEPT => 1,

        // CRITICAL: Force connection cleanup
        'enable_unsafe_event' => false,
        'discard_timeout_request' => true,
        'reload_async' => true,
        'max_wait_time' => 1,

        // CRITICAL: Add these to force proper cleanup
        'tcp_fastopen' => false,
        'open_cpu_affinity' => false,
        'cpu_affinity_ignore' => [],

        // Force socket cleanup
        'socket_dontwait' => true,
        'socket_keepalive' => true,
    ],
    'callbacks' => [
        Event::ON_WORKER_START => [Hyperf\Framework\Bootstrap\WorkerStartCallback::class, 'onWorkerStart'],
        Event::ON_PIPE_MESSAGE => [Hyperf\Framework\Bootstrap\PipeMessageCallback::class, 'onPipeMessage'],
        Event::ON_WORKER_EXIT => [Hyperf\Framework\Bootstrap\WorkerExitCallback::class, 'onWorkerExit'],

        // CRITICAL: Add connection cleanup callbacks
        Event::ON_CONNECT => function($server, $fd) {
            // Log new connections for debugging
            echo "New connection: $fd\n";
        },
        Event::ON_CLOSE => function($server, $fd) {
            // Log closed connections for debugging
            echo "Connection closed: $fd\n";
        },
    ],
];