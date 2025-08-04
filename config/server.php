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

        // Reasonable worker count - don't exceed CPU cores
        Constant::OPTION_WORKER_NUM => min(env('SERVER_WORKERS_NUMBER', swoole_cpu_num()), swoole_cpu_num()),

        Constant::OPTION_PID_FILE => base_path('runtime/hypervel.pid'),
        Constant::OPTION_OPEN_TCP_NODELAY => true,

        // Reduce max coroutines significantly to prevent resource exhaustion
        Constant::OPTION_MAX_COROUTINE => env('SERVER_MAX_COROUTINE', 10000),

        Constant::OPTION_OPEN_HTTP2_PROTOCOL => true,

        // Add max request per worker to prevent memory leaks
        Constant::OPTION_MAX_REQUEST => env('SERVER_MAX_REQUEST', 5000),

        // Reduce buffer sizes
        Constant::OPTION_SOCKET_BUFFER_SIZE => 512 * 1024, // 512KB instead of 1MB
        Constant::OPTION_BUFFER_OUTPUT_SIZE => 512 * 1024,

        // CRITICAL: Reduce connection limits to prevent file descriptor exhaustion
        Constant::OPTION_BACKLOG => 128, // Reduced from 512
        Constant::OPTION_MAX_CONN => env('SERVER_MAX_CONNECTIONS', 2048), // Much lower limit

        // Enable heartbeat detection for dead connections
        Constant::OPTION_HEARTBEAT_CHECK_INTERVAL => 30,
        Constant::OPTION_HEARTBEAT_IDLE_TIME => 300, // Reduced from 600

        // Enable proper connection closing
        Constant::OPTION_OPEN_HTTP_PROTOCOL => true,
        Constant::OPTION_OPEN_MQTT_PROTOCOL => false,

        // Reduce timeouts to free up resources faster
        Constant::OPTION_SOCKET_RECV_TIMEOUT => 2, // Reduced from 5
        Constant::OPTION_SOCKET_SEND_TIMEOUT => 2,

        // Add these critical settings to prevent file descriptor leaks
        Constant::OPTION_ENABLE_REUSE_PORT => true,
        Constant::OPTION_TCP_KEEPIDLE => 600,
        Constant::OPTION_TCP_KEEPCOUNT => 5,

        // Prevent connection accumulation
        Constant::OPTION_TCP_DEFER_ACCEPT => 5,

        // Add connection pool settings
        'max_wait_time' => 3,
        'reload_async' => true,
    ],
    'callbacks' => [
        Event::ON_WORKER_START => [Hyperf\Framework\Bootstrap\WorkerStartCallback::class, 'onWorkerStart'],
        Event::ON_PIPE_MESSAGE => [Hyperf\Framework\Bootstrap\PipeMessageCallback::class, 'onPipeMessage'],
        Event::ON_WORKER_EXIT => [Hyperf\Framework\Bootstrap\WorkerExitCallback::class, 'onWorkerExit'],
    ],
];