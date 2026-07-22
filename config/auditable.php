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
        'password',
        'password_confirmation',
        'token',
        'api_token',
        'secret',
        'credit_card',
        'cvv',
        'ssn',
    ],

];