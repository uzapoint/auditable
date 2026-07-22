<?php

namespace Uzapoint\Auditable\Traits;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Uzapoint\Auditable\Context\UserContextResolver;
use Uzapoint\Auditable\Services\AuditPublisher;

trait Auditable
{
    public static function bootAuditable(): void
    {
        static::created(function (Model $model) {
            if (!static::shouldAudit('created')) return;

            $user = UserContextResolver::resolve();

            app(AuditPublisher::class)->publish([
                'event' => 'created',
                'description' => static::buildDescription($model, 'created'),
                'log_name' => static::getLogName(),
                'subject_type' => get_class($model),
                'subject_id' => $model->id,
                'causer_id' => $user['id'],
                'causer_type' => $user['type'],
                'causer_name' => $user['name'],
                'causer_email' => $user['email'],
                'changes' => [
                    'new' => $model->toArray()
                ],
                'business_id' => $user['business_id'] ?? null,
                'properties' => [
                    'terminal_id' => $user['terminal_id'] ?? null,
                    'source' => $user['source'] ?? null,
                ]
            ]);
        });

        static::updated(function (Model $model) {
            if (static::shouldAudit('updated')) return;

            $dirty = $model->getChanges();
            unset($dirty['updated_at']);

            if (empty($dirty)) return;

            $user = UserContextResolver::resolve();
            $event = static::resolveEvent($model, $dirty);

            app(AuditPublisher::class)->publish([
                'event' => $event,
                'description' => static::buildDescription($model, $event, $dirty),
                'log_name' => static::getLogName(),
                'subject_type' => get_class($model),
                'subject_id' => $model->id,
                'causer_id' => $user['id'],
                'causer_type' => $user['type'],
                'causer_name' => $user['name'],
                'causer_email' => $user['email'],
                'changes' => [
                    'old' => array_intersect_key($model->getOriginal(), $dirty),
                    'new' => $dirty,
                ],
                'business_id' => $user['business_id'],
                'properties' => [
                    'terminal_id' => $user['terminal_id'],
                    'source' => $user['source'],
                ],
            ]);
        });

        static::deleted(function (Model $model) {
            if (!static::shouldAudit('deleted')) return;

            $user = UserContextResolver::resolve();

            app(AuditPublisher::class)->publish([
                'event' => 'deleted',
                'description' => static::buildDescription($model, 'deleted'),
                'log_name' => static::getLogName(),
                'subject_type' => get_class($model),
                'subject_id' => $model->id,
                'causer_id' => $user['id'],
                'causer_type' => $user['type'],
                'causer_name' => $user['name'],
                'causer_email' => $user['email'],
                'changes' => ['old' => $model->toArray()],
                'business_id' => $user['business_id'],
                'properties' => [
                    'terminal_id' => $user['terminal_id'],
                    'source' => $user['source'],
                ],
            ]);
        });

        if (method_exists(static::class, 'restored')) {
            static::restored(function (Model $model) {
                if (!static::shouldAudit('restored')) return;

                $user = UserContextResolver::resolve();

                app(AuditPublisher::class)->publish([
                    'event' => 'restored',
                    'description' => static::buildDescription($model, 'restored'),
                    'log_name' => static::getLogName(),
                    'subject_type' => get_class($model),
                    'subject_id' => $model->id,
                    'causer_id' => $user['id'],
                    'causer_type' => $user['type'],
                    'causer_name' => $user['name'],
                    'causer_email' => $user['email'],
                    'changes' => [],
                    'properties' => [],
                ]);
            });
        }
    }

    protected static function getLogName(): string
    {
        return Str::plural(Str::snake(class_basename(static::class)));
    }

    protected static function buildDescription(Model $model, string $event, array $dirty = []): string
    {
        $class = class_basename($model);

        return match ($event) {
            'created' => "{$class} #{$model->id} was created",

            'updated' => empty($dirty)
                ? "{$class} #{$model->id} was updated"
                : "{$class} #{$model->id} was updated: " . implode(', ', array_keys($dirty)),

            'deleted' => "{$class} #{$model->id} was deleted",
            'restored' => "{$class} #{$model->id} was restored",

            'voided' => "{$class} #{$model->id} was voided" .
                (isset($dirty['void_reason']) ? " (reason: {$dirty['void_reason']})" : ''),

            'approved' => "{$class} #{$model->id} was approved",
            'rejected' => "{$class} #{$model->id} was rejected" .
                (isset($dirty['rejection_reason']) ? " (reason: {$dirty['rejection_reason']})" : ''),

            default => "{$class} #{$model->id} was {$event}",
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