<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Refine Enabled
    |--------------------------------------------------------------------------
    |
    | Determines if Refine is active. By default, it only runs in local
    | environments. You can override this to false to completely disable.
    |
    */
    'enabled' => env('REFINE_ENABLED', env('APP_ENV') === 'local'),

    /*
    |--------------------------------------------------------------------------
    | Route Prefix
    |--------------------------------------------------------------------------
    |
    | The URL prefix for all Refine API endpoints.
    |
    */
    'route_prefix' => 'refine',

    /*
    |--------------------------------------------------------------------------
    | Middleware
    |--------------------------------------------------------------------------
    |
    | Additional middleware to apply to Refine routes. The RefineMiddleware
    | is always applied automatically.
    |
    */
    'middleware' => ['web'],

    /*
    |--------------------------------------------------------------------------
    | Instrumentation
    |--------------------------------------------------------------------------
    |
    | Controls how Blade views are instrumented with source metadata.
    |
    */
    'instrumentation' => [
        // The attribute name injected into rendered HTML elements
        'attribute_name' => 'data-source',

        // HTML tags that will receive source attributes
        'target_tags' => ['div', 'section', 'article', 'header', 'footer', 'main', 'aside', 'nav', 'form', 'table', 'ul', 'ol', 'li', 'p', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'button', 'a', 'span'],

        // Whether to instrument component tags (x-component-name)
        'instrument_components' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | File Writing
    |--------------------------------------------------------------------------
    |
    | Controls how files are written back to disk.
    |
    */
    'file_writing' => [
        // Create backups before overwriting files
        'create_backups' => true,

        // Backup directory relative to storage path
        'backup_path' => 'refine/backups',

        // Maximum number of backups to keep per file
        'max_backups' => 10,
    ],
];
