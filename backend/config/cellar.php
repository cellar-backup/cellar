<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cellar Configuration
    |--------------------------------------------------------------------------
    */

    'version' => env('CELLAR_VERSION', '0.10.0'),

    // Backup engine paths
    'borg_path' => env('CELLAR_BORG_PATH', '/usr/bin/borg'),
    'borg_passphrase' => env('CELLAR_BORG_PASSPHRASE'),
    'borg_encryption' => env('CELLAR_BORG_ENCRYPTION', 'repokey-blake2'),
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
    // No default — forces secure password via env var or random generation at first boot.
    'admin_password' => env('CELLAR_ADMIN_PASSWORD'),

    // Optional setup token — if set, the /setup endpoint requires this token.
    // Can also be provided via a file at /app/data/.setup_token
    'setup_token' => env('CELLAR_SETUP_TOKEN'),

];
