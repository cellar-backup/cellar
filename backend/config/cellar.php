<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cellar Configuration
    |--------------------------------------------------------------------------
    */

    'version' => env('CELLAR_VERSION', '0.1.0'),

    // Backup engine paths
    'borg_path' => env('CELLAR_BORG_PATH', '/usr/bin/borg'),
    'restic_path' => env('CELLAR_RESTIC_PATH', '/usr/bin/restic'),

    // Kubernetes discovery
    'kubectl_path' => env('CELLAR_KUBECTL_PATH', '/usr/local/bin/kubectl'),

    // Concurrency
    'max_parallel_jobs' => (int) env('CELLAR_MAX_PARALLEL_JOBS', 2),

    // Logging
    'log_dir' => env('CELLAR_LOG_DIR', '/app/data/logs'),

    // Default admin credentials (used by SeedDefaults command)
    'admin_name' => env('CELLAR_ADMIN_NAME', 'admin'),
    'admin_email' => env('CELLAR_ADMIN_EMAIL', 'admin@cellar.local'),
    'admin_password' => env('CELLAR_ADMIN_PASSWORD', 'admin'),

];
