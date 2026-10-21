<?php

namespace Uzapoint\Auditable\Traits;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Uzapoint\Auditable\Context\UserContextResolver;
use Uzapoint\Auditable\Services\AuditPublisher;

/**
 * Publishes created, updated (or voided / approved / rejected), deleted and
 * restored events for an Eloquent model.
 *
 * Values that must never leave the service are replaced with
 * AuditPublisher::REDACTED before publishing:
 *   - the model's $hidden attributes,
 *   - attributes with an `encrypted` cast (their original values are
 *     decrypted by Eloquent, so they would otherwise leak in plain text),
 *   - attributes listed in the model's optional $auditExclude property,
 *   - keys matching `auditable.sensitive_fields` (AuditPublisher).
 * The attribute name is kept, so the audit still shows that it changed.
 */
trait Auditable
{
    public static function bootAuditable(): void
    {
        static::created(function (Model $model) {
            static::publishAuditEvent($model, 'created', [
                'new' => static::redactForAudit($model, $model->attributesToArray()),
            ]);
        });

        static::updated(function (Model $model) {
            $dirty = $model->getChanges();
            unset($dirty['updated_at']);

            if (empty($dirty)) return;

            $event = static::resolveEvent($model, $dirty);

            static::publishAuditEvent($model, $event, [
                'old' => static::redactForAudit($model, array_intersect_key($model->getOriginal(), $dirty)),
                'new' => static::redactForAudit($model, $dirty),
            ], $dirty);
        });

        static::deleted(function (Model $model) {
            static::publishAuditEvent($model, 'deleted', [
                'old' => static::redactForAudit($model, $model->attributesToArray()),
            ]);
        });

        if (method_exists(static::class, 'restored')) {
            static::restored(function (Model $model) {
                static::publishAuditEvent($model, 'restored', []);
            });
        }
    }

    protected static function publishAuditEvent(Model $model, string $event, array $changes, array $dirty = []): void
    {
        if (!static::shouldAudit($event)) return;

        $user = UserContextResolver::resolve();

        // The user is resolved above and the payload built now; only sending
        // waits for the model's transaction to commit.
        app(AuditPublisher::class)->publish([
            'event' => $event,
            'description' => static::buildDescription($model, $event, $dirty),
            'log_name' => static::getLogName(),
            'subject_type' => get_class($model),
            'subject_id' => $model->getKey(),
            'causer_id' => $user['id'] ?? null,
            'causer_type' => $user['type'] ?? null,
            'causer_name' => $user['name'] ?? null,
            'causer_email' => $user['email'] ?? null,
            'changes' => $changes,
            'business_id' => $user['business_id'] ?? null,
            'properties' => [
                'terminal_id' => $user['terminal_id'] ?? null,
                'source' => $user['source'] ?? null,
            ],
        ], $model->getConnectionName());
    }

    /**
     * Replaces values the audit must not carry, keeping their keys.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    protected static function redactForAudit(Model $model, array $attributes): array
    {
        $excluded = array_merge(
            $model->getHidden(),
            property_exists($model, 'auditExclude') ? (array) $model->auditExclude : [],
            array_keys(array_filter(
                $model->getCasts(),
                fn ($cast) => is_string($cast) && str_starts_with($cast, 'encrypted'),
            )),
        );

        foreach ($excluded as $key) {
            if (array_key_exists($key, $attributes)) {
                $attributes[$key] = AuditPublisher::REDACTED;
            }
        }

        return $attributes;
    }

    protected static function getLogName(): string
    {
        return Str::plural(Str::snake(class_basename(static::class)));
    }

    protected static function buildDescription(Model $model, string $event, array $dirty = []): string
    {
        $class = class_basename($model);
        $id = $model->getKey();

        return match ($event) {
            'created' => "{$class} #{$id} was created",

            'updated' => empty($dirty)
                ? "{$class} #{$id} was updated"
                : "{$class} #{$id} was updated: " . implode(', ', array_keys($dirty)),

            'deleted' => "{$class} #{$id} was deleted",
            'restored' => "{$class} #{$id} was restored",

            'voided' => "{$class} #{$id} was voided" .
                (isset($dirty['void_reason']) ? " (reason: {$dirty['void_reason']})" : ''),

            'approved' => "{$class} #{$id} was approved",
            'rejected' => "{$class} #{$id} was rejected" .
                (isset($dirty['rejection_reason']) ? " (reason: {$dirty['rejection_reason']})" : ''),

            default => "{$class} #{$id} was {$event}",
        };
    }

    protected static function resolveEvent(Model $model, array $dirty): string
    {
        // Detect void
        if (
            isset($dirty['status']) &&
            $model->getOriginal('status') !== 'voided' &&
            $dirty['status'] === 'voided'
        ) {
            return 'voided';
        }

        // Detect approval
        if (
            isset($dirty['status']) &&
            $model->getOriginal('status') === 'pending' &&
            in_array($dirty['status'], ['approved', 'completed'], true)
        ) {
            return 'approved';
        }

        // Detect rejection
        if (
            isset($dirty['status']) &&
            $model->getOriginal('status') === 'pending' &&
            $dirty['status'] === 'rejected'
        ) {
            return 'rejected';
        }

        return 'updated';
    }

    protected static function shouldAudit(string $event): bool
    {
        $allowed = config('auditable.events', ['*']);

        return in_array('*', $allowed, true) || in_array($event, $allowed, true);
    }

    public function getAuditLogAttributes(): array
    {
        return $this->getFillable();
    }
}
