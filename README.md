# Uzapoint Auditable

Distributed audit logging for Laravel microservices. The package publishes audit events from each service to RabbitMQ and includes helpers for Eloquent model auditing, manual audit logs, user context resolution, OpenTelemetry trace correlation, and fallback storage when publishing fails.

## Requirements

- PHP 8.4 or newer
- Laravel 11, 12, or compatible 13.x release
- RabbitMQ
- Redis if you consume events with `uzapoint/eventbus-core` idempotency enabled
- A central audit/log service with a table that can store consumed audit records

## Installation

Install the package in every microservice that should publish audit events:

```bash
composer require uzapoint/auditable
```

The package depends on `uzapoint/eventbus-core`, which provides the RabbitMQ publisher and consumer command.

If Laravel package discovery is not available, register the service providers manually in `config/app.php`:

```php
'providers' => [
    Uzapoint\EventBus\EventBusServiceProvider::class,
    Uzapoint\Auditable\AuditServiceProvider::class,
],
```

## Configure RabbitMQ

`AuditPublisher` publishes to the RabbitMQ topic exchange `audit.events` with the routing key `audit.events`. The event bus reads RabbitMQ connection values from `config('queue.connections.rabbitmq')`, so add a RabbitMQ connection to `config/queue.php` in each publishing service:

```php
'connections' => [
    // ...

    'rabbitmq' => [
        'host' => env('RABBITMQ_HOST', '127.0.0.1'),
        'port' => env('RABBITMQ_PORT', 5672),
        'user' => env('RABBITMQ_USER', 'guest'),
        'password' => env('RABBITMQ_PASSWORD', 'guest'),
        'vhost' => env('RABBITMQ_VHOST', '/'),
    ],
],
```

Add the matching environment variables:

```dotenv
RABBITMQ_HOST=127.0.0.1
RABBITMQ_PORT=5672
RABBITMQ_USER=guest
RABBITMQ_PASSWORD=guest
RABBITMQ_VHOST=/
```

## Configure Auditing

The package merges `config/auditable.php` by default. To customize config and publish the fallback migration into an application, run:

```bash
php artisan vendor:publish --tag=auditable
```

This publishes:

- `config/auditable.php`
- `database/migrations/2026_07_15_000001_create_failed_audit_events_table.php`

You can also publish each asset type separately:

```bash
php artisan vendor:publish --tag=auditable-config
php artisan vendor:publish --tag=auditable-migrations
```

Set the service identity in `.env`. These values are added to every published audit payload so the central audit service can identify the source service.

```dotenv
AUDIT_SERVICE_NAME=inventory-service
AUDIT_SERVICE_ENV=production
AUDIT_FALLBACK_ENABLED=true
AUDIT_FALLBACK_TABLE=failed_audit_events
AUDIT_FALLBACK_RETRY_AFTER=5
```

Important config values:

```php
return [
    'service' => [
        'name' => env('AUDIT_SERVICE_NAME', config('app.name')),
        'environment' => env('AUDIT_SERVICE_ENV', config('app.env')),
    ],

    'events' => [
        'created',
        'updated',
        'deleted',
        'voided',
        'approved',
        'rejected',
        'restored',
    ],

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
```

Use `events => ['*']` to publish every supported event. Sensitive fields are recursively replaced with `***REDACTED***` inside `changes` and `properties`.

## Enable Model Auditing

Add the `Auditable` trait to any Eloquent model that should publish audit events.

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Uzapoint\Auditable\Traits\Auditable;

class Order extends Model
{
    use Auditable;
    use SoftDeletes;

    protected $fillable = [
        'customer_id',
        'status',
        'total',
        'void_reason',
        'rejection_reason',
    ];
}
```

The trait publishes:

- `created` when a model is created
- `updated` when persisted attributes change
- `deleted` when a model is deleted
- `restored` for models using soft deletes
- `voided` when `status` changes to `voided`
- `approved` when `status` changes from `pending` to `approved` or `completed`
- `rejected` when `status` changes from `pending` to `rejected`

Each event includes the subject type, subject id, causer context, old/new changes, service metadata, host, timestamp, optional batch UUID, and current OpenTelemetry trace/span ids.

## Manual Audit Logs

Use the facade when you need to audit an action that is not tied directly to an Eloquent lifecycle event:

```php
use Uzapoint\Auditable\Facades\Audit;

Audit::log('invoice.sent', 'Invoice #123 was sent to the customer', [
    'log_name' => 'invoices',
    'subject_type' => App\Models\Invoice::class,
    'subject_id' => 123,
    'causer_id' => auth('api')->id(),
    'causer_type' => App\Models\User::class,
    'causer_name' => optional(auth('api')->user())->name,
    'causer_email' => optional(auth('api')->user())->email,
    'changes' => null,
    'properties' => [
        'channel' => 'email',
    ],
]);
```

You can also inject `Uzapoint\Auditable\Services\AuditPublisher` and call `publish(array $payload)` directly when you already have the full audit payload.

## User Context

`UserContextResolver` resolves the causer in this order:

1. Gateway headers and request input
2. Request body values
3. Context set for a queued job or consumed message
4. `auth('api')->user()`
5. A system context

For HTTP requests through an API gateway, pass these values when available:

```text
X-Person-ID: 42
X-Business-ID: 1001
X-Person: base64-encoded JSON person object
X-User-Permissions: base64-encoded JSON permissions array
X-User-Roles: base64-encoded JSON roles array
```

The resolver also reads `user_id`, `person`, `terminal_id`, and `terminal_information` from the request body.

For queued jobs or message handlers, set the user context before changing audited models:

```php
use Uzapoint\Auditable\Context\UserContextResolver;

UserContextResolver::set($message['user_context']);

try {
    $order->update(['status' => 'approved']);
} finally {
    UserContextResolver::clear();
}
```

To pass context into a new message, include:

```php
'user_context' => UserContextResolver::forMessage(),
```

## Batch Correlation

Use `BatchContext` when a workflow changes several models and you want all audit events to share a single batch id:

```php
use Uzapoint\Auditable\Context\BatchContext;

$batchUuid = BatchContext::set();

try {
    $invoice->update(['status' => 'paid']);
    $payment->update(['status' => 'reconciled']);
} finally {
    BatchContext::clear();
}
```

The batch UUID is added to `meta.batch_uuid` on every audit payload published while the context is active.

## Fallback Storage

When RabbitMQ publishing fails, the publisher can insert the payload into a local fallback table for retry. Publish the migration and run migrations:

```bash
php artisan vendor:publish --tag=auditable-migrations
php artisan migrate
```

The table defaults to `failed_audit_events` and stores the JSON payload, failure time, retry time, attempts, processed time, and error text.

## Central Audit Service Consumer

In the central audit service, consume the `audit.events` exchange and route messages to `ProcessAuditEvents`.

Publish the event bus config:

```bash
php artisan vendor:publish --tag=eventbus-config
```

Configure `config/eventbus.php`:

```php
use Uzapoint\Auditable\Jobs\ProcessAuditEvents;

return [
    'exchanges' => [
        'audit.events',
    ],

    'queues' => [
        [
            'name' => 'audit_service.audit_events',
            'exchange' => 'audit.events',
            'routing_keys' => [
                'audit.events',
            ],
        ],
    ],

    'handlers' => [
        'audit.events' => ProcessAuditEvents::class,
    ],

    'dead_letter' => [
        'enabled' => true,
        'ttl' => 86400000,
        'max_retries' => 3,
        'exchange_prefix' => 'dlx.',
    ],
];
```

Run the consumer:

```bash
php artisan eventbus:consume --queue=audit_service.audit_events
```

`ProcessAuditEvents` inserts records into `config('auditable.table', 'activity_logs')`. Make sure the central audit service has a compatible `activity_logs` table or set `auditable.table` to the table name you want to use.

The expected columns are:

```text
uuid
event
description
log_name
subject_type
subject_id
causer_type
causer_id
causer_name
causer_email
changes
properties
source_service
source_environment
source_host
batch_uuid
created_at
recorded_at
```

## Typical Microservice Setup Checklist

1. Install `uzapoint/auditable`.
2. Add the RabbitMQ connection to `config/queue.php`.
3. Set `AUDIT_SERVICE_NAME`, `AUDIT_SERVICE_ENV`, and RabbitMQ environment variables.
4. Run `php artisan vendor:publish --tag=auditable` to publish package config and fallback migration.
5. Run `php artisan migrate` if local failed-publish storage is required.
6. Add `Uzapoint\Auditable\Traits\Auditable` to each model that should publish audit events.
7. Forward gateway user headers or set `UserContextResolver` in queued/message workflows.
8. Run the central audit service consumer for the `audit.events` queue.

## Troubleshooting

- If no events reach the audit service, verify RabbitMQ credentials in `config('queue.connections.rabbitmq')` and confirm the central service is consuming `audit.events`.
- If audit records have `source_service=unknown`, set `AUDIT_SERVICE_NAME` in the publishing service.
- If causer fields are empty, forward the gateway headers or set `UserContextResolver` before updating audited models.
- If sensitive data appears in payloads, add the exact field names to `auditable.sensitive_fields`.
- If consumed events are ignored, confirm `config/eventbus.php` maps the `audit.events` routing key to `Uzapoint\Auditable\Jobs\ProcessAuditEvents::class`.
