<?php

declare(strict_types=1);

use Hypervel\Support\Str;

return [
    'default' => env('DB_CONNECTION', 'mysql'),

    'connections' => [
        'mysql' => [
            'driver' => env('DB_DRIVER', 'mysql'),
            'host' => env('DB_HOST', 'localhost'),
            'database' => env('DB_DATABASE', 'hypervel'),
            'port' => env('DB_PORT', 3306),
            'username' => env('DB_USERNAME', 'root'),
            'password' => env('DB_PASSWORD', ''),
            'charset' => env('DB_CHARSET', 'utf8mb4'),
            'collation' => env('DB_COLLATION', 'utf8mb4_unicode_ci'),
            'prefix' => env('DB_PREFIX', ''),
            'pool' => [
                'min_connections' => 1,
                // Conservative connection pool - prevent file descriptor exhaustion
                'max_connections' => min(env('DB_MAX_CONNECTIONS', 20), swoole_cpu_num() * 2),
                'connect_timeout' => 10.0,
                'wait_timeout' => 3.0,
                'heartbeat' => 30,
                'max_idle_time' => (float) env('DB_MAX_IDLE_TIME', 60),
            ],
        ],

        'pgsql' => [
            'driver' => env('DB_DRIVER', 'pgsql'),
            'host' => env('DB_HOST', 'localhost'),
            'database' => env('DB_DATABASE', 'hypervel'),
            'schema' => env('DB_SCHEMA', 'public'),
            'port' => env('DB_PORT', 5432),
            'username' => env('DB_USERNAME', 'root'),
            'password' => env('DB_PASSWORD', ''),
            'charset' => env('DB_CHARSET', 'utf8'),
            'pool' => [
                'min_connections' => 1,
                'max_connections' => min(env('DB_MAX_CONNECTIONS', 15), swoole_cpu_num() * 2),
                'connect_timeout' => 10.0,
                'wait_timeout' => 3.0,
                'heartbeat' => 30,
                'max_idle_time' => (float) env('DB_MAX_IDLE_TIME', 60),
            ],
        ],

        'sqlite' => [
            'driver' => 'sqlite',
            'url' => env('DATABASE_URL'),
            'database' => env('DB_DATABASE', database_path('database.sqlite')),
            'prefix' => '',
            'foreign_key_constraints' => env('DB_FOREIGN_KEYS', true),
        ],

        'sqlite_testing' => [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => env('DB_FOREIGN_KEYS', true),
        ],
    ],

    'migrations' => 'migrations',

    'redis' => [
        'options' => [
            'prefix' => env('REDIS_PREFIX', Str::slug(env('APP_NAME', 'hypervel'), '_') . '_database_'),
        ],

        'default' => [
            'host' => env('REDIS_HOST', 'localhost'),
            'auth' => env('REDIS_AUTH', null),
            'port' => (int) env('REDIS_PORT', 6379),
            'db' => (int) env('REDIS_DB', 0),
            'pool' => [
                'min_connections' => 2,
                // Conservative Redis connections
                'max_connections' => min(env('REDIS_MAX_CONNECTIONS', 15), swoole_cpu_num() * 2),
                'connect_timeout' => 10.0,
                'wait_timeout' => 3.0,
                'heartbeat' => 30,
                'max_idle_time' => (float) env('REDIS_MAX_IDLE_TIME', 60),
            ],
        ],

        'queue' => [
            'host' => env('REDIS_HOST', 'localhost'),
            'auth' => env('REDIS_AUTH', null),
            'port' => (int) env('REDIS_PORT', 6379),
            'db' => (int) env('REDIS_DB', 1),
            'pool' => [
                'min_connections' => 1,
                'max_connections' => min(env('REDIS_MAX_CONNECTIONS', 10), swoole_cpu_num()),
                'connect_timeout' => 10.0,
                'wait_timeout' => 3.0,
                'heartbeat' => 30,
                'max_idle_time' => (float) env('REDIS_MAX_IDLE_TIME', 60),
            ],
        ],
    ],
];