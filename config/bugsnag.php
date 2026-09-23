<?php

$config = [

    /*
    |--------------------------------------------------------------------------
    | API Key
    |--------------------------------------------------------------------------
    */

    'api_key' => env('BUGSNAG_API_KEY', ''),

    /*
    |--------------------------------------------------------------------------
    | App Type / Version
    |--------------------------------------------------------------------------
    */

    'app_type' => env('BUGSNAG_APP_TYPE', 'web'),

    'app_version' => env('BUGSNAG_APP_VERSION', \Elegant\Foundation\Application::VERSION),

    /*
    |--------------------------------------------------------------------------
    | Release Stages
    |--------------------------------------------------------------------------
    */

    'release_stage' => env('BUGSNAG_RELEASE_STAGE', defined('ENVIRONMENT') ? ENVIRONMENT : 'production'),

    'notify_release_stages' => empty(env('BUGSNAG_NOTIFY_RELEASE_STAGES'))
        ? ['production', 'staging']
        : explode(',', str_replace(' ', '', env('BUGSNAG_NOTIFY_RELEASE_STAGES'))),

    /*
    |--------------------------------------------------------------------------
    | Batch Sending / Code Snippets
    |--------------------------------------------------------------------------
    */

    'batch_sending' => filter_var(env('BUGSNAG_BATCH_SENDING', false), FILTER_VALIDATE_BOOLEAN),

    'send_code' => filter_var(env('BUGSNAG_SEND_CODE', true), FILTER_VALIDATE_BOOLEAN),

    /*
    |--------------------------------------------------------------------------
    | Endpoints (Bugsnag On-premise)
    |--------------------------------------------------------------------------
    */

    'endpoint' => env('BUGSNAG_ENDPOINT'),

    'session_endpoint' => env('BUGSNAG_SESSION_ENDPOINT', env('BUGSNAG_SESSIONS_ENDPOINT')),

    'build_endpoint' => env('BUGSNAG_BUILD_ENDPOINT'),

    /*
    |--------------------------------------------------------------------------
    | Paths
    |--------------------------------------------------------------------------
    */

    'project_root' => env('BUGSNAG_PROJECT_ROOT', base_path()),

    'project_root_regex' => env('BUGSNAG_PROJECT_ROOT_REGEX'),

    'strip_path' => env('BUGSNAG_STRIP_PATH', base_path()),

    'strip_path_regex' => env('BUGSNAG_STRIP_PATH_REGEX'),

    /*
    |--------------------------------------------------------------------------
    | Filters / Redaction
    |--------------------------------------------------------------------------
    */

    'filters' => empty(env('BUGSNAG_FILTERS')) ? [
        'password',
        'password_confirmation',
        'password_current',
        'token',
        'api_key',
        'secret',
        'private_key',
        'auth',
        'authorization',
        'cookie',
        'session_id',
        'csrf_token',
        '_token',
    ] : explode(',', str_replace(' ', '', env('BUGSNAG_FILTERS'))),

    'redacted_keys' => empty(env('BUGSNAG_REDACTED_KEYS'))
        ? null
        : explode(',', str_replace(' ', '', env('BUGSNAG_REDACTED_KEYS'))),

    /*
    |--------------------------------------------------------------------------
    | Hostname / Discard Classes
    |--------------------------------------------------------------------------
    */

    'hostname' => env('BUGSNAG_HOSTNAME'),

    'discard_classes' => empty(env('BUGSNAG_DISCARD_CLASSES'))
        ? []
        : explode(',', str_replace(' ', '', env('BUGSNAG_DISCARD_CLASSES'))),

    /*
    |--------------------------------------------------------------------------
    | Callbacks / User / Sessions
    |--------------------------------------------------------------------------
    */

    'callbacks' => filter_var(env('BUGSNAG_CALLBACKS', true), FILTER_VALIDATE_BOOLEAN),

    'user' => filter_var(env('BUGSNAG_USER', true), FILTER_VALIDATE_BOOLEAN),

    'auto_capture_sessions' => filter_var(env('BUGSNAG_CAPTURE_SESSIONS', false), FILTER_VALIDATE_BOOLEAN),

    'max_breadcrumbs' => (int) env('BUGSNAG_MAX_BREADCRUMBS', 50),

    /*
    |--------------------------------------------------------------------------
    | Proxy
    |--------------------------------------------------------------------------
    */

    'proxy' => array_filter([
        'http' => env('HTTP_PROXY'),
        'https' => env('HTTPS_PROXY'),
        'no' => empty(env('NO_PROXY')) ? null : explode(',', str_replace(' ', '', env('NO_PROXY'))),
    ]),
];
