<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Filesystem Disk
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default filesystem disk that should be used
    | by the framework. The "local" disk, as well as a variety of cloud
    | based disks are available to your application for file storage.
    |
    */

    'default' => env('FILESYSTEM_DISK', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Avatar Disk
    |--------------------------------------------------------------------------
    |
    | Which disk uploaded profile photos live on. Read by ProfileController and
    | by User::avatarUrl(), which is what puts `avatar_url` on every JSON user
    | payload — so this one value decides both where a photo is written and the
    | URL the SPA loads it from.
    |
    | Local dev uses "public" (storage/app/public, exposed via the
    | public/storage symlink created by `php artisan storage:link`).
    |
    | Most hosts that deploy from git have an EPHEMERAL filesystem: every deploy
    | starts from a fresh container, so anything written to the public disk is
    | silently gone. Set AVATAR_DISK=r2 there. Hosts with a real persistent disk
    | (a VPS, or a mounted volume) can keep "public" safely.
    |
    */

    'avatars' => env('AVATAR_DISK', 'public'),

    /*
    |--------------------------------------------------------------------------
    | Filesystem Disks
    |--------------------------------------------------------------------------
    |
    | Below you may configure as many filesystem disks as necessary, and you
    | may even configure multiple disks for the same driver. Examples for
    | most supported storage drivers are configured here for reference.
    |
    | Supported drivers: "local", "ftp", "sftp", "s3"
    |
    */

    'disks' => [

        'local' => [
            'driver' => 'local',
            'root' => storage_path('app/private'),
            'serve' => true,
            'throw' => false,
            'report' => false,
        ],

        'public' => [
            'driver' => 'local',
            'root' => storage_path('app/public'),
            'url' => rtrim(env('APP_URL', 'http://localhost'), '/').'/storage',
            'visibility' => 'public',
            'throw' => false,
            'report' => false,
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
            'report' => false,
        ],

        /*
         * Cloudflare R2. S3-compatible, so it uses the same driver — kept as its
         * own disk rather than overloading "s3" so the two can coexist and so
         * the R2-specific requirements are stated where they are set:
         *
         *  - region MUST be "auto"
         *  - endpoint is https://<account-id>.r2.cloudflarestorage.com
         *  - R2_URL is the PUBLIC read URL, and is not optional for avatars:
         *    User::avatarUrl() hands it straight to an <img> tag, so the bucket
         *    needs public access enabled (an r2.dev URL or a custom domain).
         *    Without it Storage::url() returns a path the browser cannot load.
         *  - path-style endpoints, because R2 does not do virtual-host buckets.
         */
        'r2' => [
            'driver' => 's3',
            'key' => env('R2_ACCESS_KEY_ID'),
            'secret' => env('R2_SECRET_ACCESS_KEY'),
            'region' => env('R2_DEFAULT_REGION', 'auto'),
            'bucket' => env('R2_BUCKET'),
            'url' => env('R2_URL'),
            'endpoint' => env('R2_ENDPOINT'),
            'use_path_style_endpoint' => true,
            'visibility' => 'public',
            'throw' => false,
            'report' => false,
        ],

    ],

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
