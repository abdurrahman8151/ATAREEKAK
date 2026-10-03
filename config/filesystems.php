<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Filesystem Disk
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default filesystem disk that should be used
    | by the framework. The "local" disk, as well as a variety of cloud
    | based disks are available to your application. Just store away!
    |
    */

    'default' => env('FILESYSTEM_DISK', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Filesystem Disks
    |--------------------------------------------------------------------------
    |
    | Here you may configure as many filesystem "disks" as you wish, and you
    | may even configure multiple disks of the same driver. Defaults have
    | been set up for each driver as an example of the required values.
    |
    | Supported Drivers: "local", "ftp", "sftp", "s3"
    |
    */

    'disks' => [

        'local' => [
            'driver' => 'local',
            'root' => storage_path('app'),
            'throw' => false,
        ],

        'public' => [
            'driver' => 'local',
            'root' => storage_path('app/public'),
            'url' => env('APP_URL').'/storage',
            'visibility' => 'public',
            'throw' => false,
        ],

        's3' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION'),
            'bucket' => env('AWS_BUCKET'),
            'url' => env('AWS_URL'),
            'endpoint' => env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            'throw' => false,
        ],

        /*
         * ------------------------------------------------------------------
         * Decision un1 (owner, 2026-10-02, Option A): MinIO - "if it doesn't
         * require payments and so on, free". MinIO speaks the S3 API, so this
         * is the stock Laravel s3 driver pointed at a self-hosted endpoint;
         * no extra package is required.
         *
         * WHY A SEPARATE DISK RATHER THAN REUSING `s3`: `s3` means "the cloud
         * provider" to every reader of this file, and the two endpoints behave
         * differently (`use_path_style_endpoint`, no region in the usual sense).
         * Keeping them apart means an operator can run MinIO in staging and
         * AWS in production without touching application code.
         *
         * THIS DISK IS PRIVATE BY DESIGN. KYC identity documents are currently
         * uploaded to the `public` disk and were served as `asset('storage/...')`
         * - a public URL for a face ID photo (R2 sec 46 / decision 1b). Moving
         * them here is the half of RV-01 that is still open. `visibility` is
         * `private` and there is deliberately NO `url` key: a disk with a public
         * url is exactly the defect being removed.
         *
         * ENABLING IT IS A DEPLOY DECISION, NOT A CODE ONE. `DOCUMENTS_DISK`
         * (below) defaults to `public` so that flipping this on cannot break
         * document upload on a host that has no MinIO yet. Set DOCUMENTS_DISK=minio
         * only once the bucket exists and DOCUMENTS_S3_* are populated.
         */
        'minio' => [
            'driver' => 's3',
            'key' => env('MINIO_ACCESS_KEY'),
            'secret' => env('MINIO_SECRET_KEY'),
            'region' => env('MINIO_REGION', 'us-east-1'),
            'bucket' => env('MINIO_BUCKET', 'syride-private'),
            'endpoint' => env('MINIO_ENDPOINT'),
            // MinIO serves buckets as a PATH (/syride-private/obj), not a subdomain, so
            // path-style addressing is required. Forgetting this is the classic
            // "works against AWS, 404s against MinIO" failure.
            'use_path_style_endpoint' => env('MINIO_USE_PATH_STYLE', true),
            'visibility' => 'private',
            'throw' => false,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | KYC document disk (decision un1)
    |--------------------------------------------------------------------------
    |
    | Which disk `DocumentController` stores identity documents on. Defaults to
    | `public` - the historical behaviour - so deploying un1 cannot break uploads on
    | a host with no object storage yet. Setting it to `minio` is the one-line deploy
    | change that moves identity documents off the public disk, which is the half of
    | RV-01 that decision 1b left open.
    |
    */

    'documents_disk' => env('DOCUMENTS_DISK', 'public'),

    /*
    |--------------------------------------------------------------------------
    | Symbolic Links
    |--------------------------------------------------------------------------
    |
    | Here you may configure the symbolic links that will be created when the
    | `storage:link` Artisan command is executed. The array keys should be
    | the locations of the links and the values should be their targets.
    |
    */

    'links' => [
        public_path('storage') => storage_path('app/public'),
    ],

];
