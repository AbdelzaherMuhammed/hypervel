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

        Constant::OPTION_TASK_ENABLE_COROUTINE => true,

        Constant::OPTION_ENABLE_COROUTINE => true,
        Constant::OPTION_MAX_COROUTINE => 100000,

        // Worker Configuration
        Constant::OPTION_WORKER_NUM => env('SERVER_WORKERS_NUMBER', swoole_cpu_num() * 2),
        Constant::OPTION_TASK_WORKER_NUM => env('TASK_WORKERS_NUMBER', swoole_cpu_num()),

        // Process Management
        Constant::OPTION_PID_FILE => base_path('runtime/hypervel.pid'),
        Constant::OPTION_MAX_REQUEST => 100000,
        Constant::OPTION_MAX_REQUEST_GRACE => 10000, // Graceful worker restart

        // Network Optimization
        Constant::OPTION_OPEN_TCP_NODELAY => true,
        Constant::OPTION_TCP_FASTOPEN => true,
        Constant::OPTION_OPEN_HTTP2_PROTOCOL => true,

        Constant::OPTION_MAX_CONNECTION => 2000,
        Constant::OPTION_HEARTBEAT_CHECK_INTERVAL => 60,
        Constant::OPTION_HEARTBEAT_IDLE_TIME => 600,

        // Buffer Configuration
        Constant::OPTION_SOCKET_BUFFER_SIZE => 2 * 1024 * 1024,
        Constant::OPTION_BUFFER_OUTPUT_SIZE => 2 * 1024 * 1024,
        Constant::OPTION_PACKAGE_MAX_LENGTH => 8 * 1024 * 1024,

        Constant::OPTION_BACKLOG => 2048, // Listen backlog
        Constant::OPTION_TCP_DEFER_ACCEPT => 5,

        Constant::OPTION_OPEN_CPU_AFFINITY => true,
        Constant::OPTION_TCP_KEEPIDLE => 600,
        Constant::OPTION_TCP_KEEPINTERVAL => 60,
        Constant::OPTION_TCP_KEEPCOUNT => 5,
    ],
    'callbacks' => [
        Event::ON_WORKER_START => [Hyperf\Framework\Bootstrap\WorkerStartCallback::class, 'onWorkerStart'],
        Event::ON_PIPE_MESSAGE => [Hyperf\Framework\Bootstrap\PipeMessageCallback::class, 'onPipeMessage'],
        Event::ON_WORKER_EXIT => [Hyperf\Framework\Bootstrap\WorkerExitCallback::class, 'onWorkerExit'],

        // Add task worker callbacks if using task workers
        Event::ON_TASK => [Hyperf\Framework\Bootstrap\TaskCallback::class, 'onTask'],
        Event::ON_FINISH => [Hyperf\Framework\Bootstrap\FinishCallback::class, 'onFinish'],
    ],
];