<?php

return [

    'domain' => null,

    'path' => 'horizon',

    'use' => 'default',

    'prefix' => 'horizon:',

    'middleware' => ['web'],

    'waiter' => [
        'enabled' => false,
        'events' => [],
    ],

    'trim' => [
        'recent' => 60,
        'pending' => 60,
        'completed' => 60,
        'recent_failed' => 10080,
        'failed' => 10080,
        'monitored' => 10080,
    ],

    'silenced' => [],

    'environments' => [
        'production' => [
            'supervisor-1' => [
                'maxProcesses' => (int) env('CELLAR_MAX_PARALLEL_JOBS', 2),
                'balanceMaxShift' => 1,
                'balanceCooldown' => 3,
                'connection' => 'redis',
                'queue' => ['default'],
                'tries' => 2,
                'timeout' => 7200,
            ],
        ],

        'local' => [
            'supervisor-1' => [
                'maxProcesses' => 2,
                'connection' => 'redis',
                'queue' => ['default'],
                'tries' => 2,
                'timeout' => 7200,
            ],
        ],
    ],
];
