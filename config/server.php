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

        // Reduce worker count if you have too many
        Constant::OPTION_WORKER_NUM => min(env('SERVER_WORKERS_NUMBER', swoole_cpu_num()), 16),

        Constant::OPTION_PID_FILE => base_path('runtime/hypervel.pid'),
        Constant::OPTION_OPEN_TCP_NODELAY => true,

        // Reduce max coroutines to prevent resource exhaustion
        Constant::OPTION_MAX_COROUTINE => env('SERVER_MAX_COROUTINE', 50000),

        Constant::OPTION_OPEN_HTTP2_PROTOCOL => true,

        // Add max request per worker to prevent memory leaks
        Constant::OPTION_MAX_REQUEST => env('SERVER_MAX_REQUEST', 10000),

        // Reduce buffer sizes if not needed
        Constant::OPTION_SOCKET_BUFFER_SIZE => 1024 * 1024,
        Constant::OPTION_BUFFER_OUTPUT_SIZE => 1024 * 1024,

        // Add connection limits
        Constant::OPTION_BACKLOG => 512,
        Constant::OPTION_MAX_CONN => env('SERVER_MAX_CONNECTIONS', 10000),

        // Enable heartbeat detection for dead connections
        Constant::OPTION_HEARTBEAT_CHECK_INTERVAL => 30,
        Constant::OPTION_HEARTBEAT_IDLE_TIME => 600,

        // Enable proper connection closing
        Constant::OPTION_OPEN_HTTP_PROTOCOL => true,
        Constant::OPTION_OPEN_MQTT_PROTOCOL => false,

        // Reduce timeouts to free up resources faster
        Constant::OPTION_SOCKET_RECV_TIMEOUT => 5,
        Constant::OPTION_SOCKET_SEND_TIMEOUT => 5,
    ],
    'callbacks' => [
        Event::ON_WORKER_START => [Hyperf\Framework\Bootstrap\WorkerStartCallback::class, 'onWorkerStart'],
        Event::ON_PIPE_MESSAGE => [Hyperf\Framework\Bootstrap\PipeMessageCallback::class, 'onPipeMessage'],
        Event::ON_WORKER_EXIT => [Hyperf\Framework\Bootstrap\WorkerExitCallback::class, 'onWorkerExit'],
    ],
];