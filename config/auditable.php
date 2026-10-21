<?php
// packages/auditable/config/auditable.php

return [

    /*
    |--------------------------------------------------------------------------
    | Service Identity
    |--------------------------------------------------------------------------
    */
    'service' => [
        'name' => env('AUDIT_SERVICE_NAME', config('app.name', 'unknown')),
        'environment' => env('AUDIT_SERVICE_ENV', config('app.env', 'production')),
    ],

    /*
    |--------------------------------------------------------------------------
    | Fallback Storage
    |--------------------------------------------------------------------------
    | When RabbitMQ is unavailable, store events locally for retry.
    */
    'fallback' => [
        'enabled' => env('AUDIT_FALLBACK_ENABLED', true),
        'table' => env('AUDIT_FALLBACK_TABLE', 'failed_audit_events'),
        'retry_after_minutes' => env('AUDIT_FALLBACK_RETRY_AFTER', 5),
    ],

    /*
    |--------------------------------------------------------------------------
    | Publish After Commit
    |--------------------------------------------------------------------------
    | Inside a database transaction, send audit events only after it commits
    | and drop them if it rolls back. Outside a transaction they are sent
    | immediately either way.
    */
    'after_commit' => env('AUDIT_AFTER_COMMIT', true),

    /*
    |--------------------------------------------------------------------------
    | Event Filtering
    |--------------------------------------------------------------------------
    | Control which events are published. Set to ['*'] for all.
    */
    'events' => [
        'created',
        'updated',
        'deleted',
        'voided',
        'approved',
        'rejected',
        'restored',
    ],

    /*
    |--------------------------------------------------------------------------
    | Sensitive Fields
    |--------------------------------------------------------------------------
    | Fields that should never be logged (passwords, tokens, etc.)
    */
    'sensitive_fields' => [
        // Matched case-insensitively, as the whole key or a `_`-suffix:
        // 'password' also covers 'initiator_password', 'secret' covers
        // 'client_secret'. Hidden and encrypted model attributes are always
        // redacted by the Auditable trait, whatever this list says.
        'password',
        'password_confirmation',
        'token',
        'api_token',
        'access_token',
        'refresh_token',
        'secret',
        'secret_key',
        'api_key',
        'private_key',
        'passkey',
        'authorization',
        'credit_card',
        'cvv',
        'ssn',
    ],

    /*
    |--------------------------------------------------------------------------
    | Local Auth Guard
    |--------------------------------------------------------------------------
    | Used to find the causer when a request did not come through the gateway.
    | Skipped when the guard is not defined in config/auth.php.
    */
    'auth_guard' => env('AUDIT_AUTH_GUARD', 'api'),

];