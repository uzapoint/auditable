<?php

namespace Uzapoint\Auditable\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use OpenTelemetry\API\Trace\Span;
use Uzapoint\Auditable\Context\BatchContext;
use Uzapoint\EventBus\RabbitMQService;

class AuditPublisher
{
    public const string REDACTED = '***REDACTED***';

    /**
     * Publishes to RabbitMQ; on failure stores the event in the fallback
     * table. The payload is redacted first, so neither path can carry a
     * sensitive value.
     *
     * Inside a database transaction the event is sent only after the
     * outermost commit, and dropped if the transaction rolls back, so the
     * audit never records changes that were not saved. Everything that can
     * change before then (batch id, trace ids, publish time) is captured now.
     * Outside a transaction it is sent immediately.
     *
     * @param  string|null  $connection  Connection whose transaction to wait for (default connection when null).
     */
    public function publish(array $payload, ?string $connection = null): void
    {
        $payload = $this->sanitizePayload($payload);

        try {
            $payload = $this->enrichPayload($payload);
        } catch (\Throwable $e) {
            $this->fail($payload, $e);
            return;
        }

        if (!filter_var(config('auditable.after_commit', true), FILTER_VALIDATE_BOOL)) {
            $this->send($payload);
            return;
        }

        try {
            DB::connection($connection)->afterCommit(fn () => $this->send($payload));
        } catch (\Throwable $e) {
            // The connection could not be resolved: never lose the event.
            Log::warning('Audit event could not wait for commit, sending now: ' . $e->getMessage());
            $this->send($payload);
        }
    }

    private function send(array $payload): void
    {
        try {
            app(RabbitMQService::class)->publish(
                exchange: 'audit.events',
                routingKey: 'audit.events',
                data: $payload
            );
        } catch (\Throwable $e) {
            $this->fail($payload, $e);
        }
    }

    private function fail(array $payload, \Throwable $e): void
    {
        $this->handleFallback($payload);
        Log::error("Failed to publish audit event: " . $e->getMessage());
    }

    /**
     * @throws \Exception
     */
    public function log(string $event, string $description, array $context = []): void
    {
        $this->publish([
            'event' => $event,
            'description' => $description,
            'log_name' => $context['log_name'] ?? 'default',
            'subject_type' => $context['subject_type'] ?? null,
            'subject_id' => $context['subject_id'] ?? null,
            'causer_id' => $context['causer_id'] ?? null,
            'causer_type' => $context['causer_type'] ?? null,
            'causer_name' => $context['causer_name'] ?? null,
            'causer_email' => $context['causer_email'] ?? null,
            'changes' => $context['changes'] ?? null,
            'business_id' => $context['business_id'] ?? null,
            'properties' => $context['properties'] ?? [],
        ]);
    }

    private function enrichPayload($payload)
    {
        $span = Span::getCurrent();
        $context = $span->getContext();

        $payload['trace_context'] = [
            'trace_id' => $context->isValid() ? $context->getTraceId() : null,
            'span_id' => $context->isValid() ? $context->getSpanId() : null,
            'trace_flags' => $context->isValid() ? $context->getTraceFlags() : null,
        ];


        $payload['meta'] = [
            'published_at' => now()->toIso8601String(),
            'publisher_service' => config('auditable.service.name'),
            'publisher_environment' => config('auditable.service.environment'),
            'publisher_host' => gethostname(),
            'publisher_version' => config('app.version', '2.0.0'),
            'batch_uuid' => BatchContext::get()
        ];
        return $payload;
    }

    /**
     * Redacts keys named in `auditable.sensitive_fields`, at any depth.
     * Matching ignores case and also covers suffixed names: `password`
     * matches `Password` and `initiator_password`, `secret` matches
     * `client_secret`.
     */
    private function sanitizePayload(array $payload): array
    {
        $sensitive = array_map('strtolower', (array) config('auditable.sensitive_fields', []));

        $isSensitive = function ($key) use ($sensitive): bool {
            if (!is_string($key)) return false;

            $key = strtolower($key);
            foreach ($sensitive as $name) {
                if ($key === $name || str_ends_with($key, '_' . $name)) return true;
            }

            return false;
        };

        $scrub = function ($data) use ($isSensitive, &$scrub) {
            if (!is_array($data)) return $data;

            foreach ($data as $key => $value) {
                if ($isSensitive($key)) {
                    $data[$key] = self::REDACTED;
                } elseif (is_array($value)) {
                    $data[$key] = $scrub($value);
                }
            }

            return $data;
        };

        if (isset($payload['changes'])) {
            $payload['changes'] = $scrub($payload['changes']);
        }

        if (isset($payload['properties'])) {
            $payload['properties'] = $scrub($payload['properties']);
        }

        return $payload;
    }

    private function handleFallback(array $payload): void
    {
        // `fallback_enabled` was the key read up to v1.0.3; it is still honoured if set.
        $enabled = config('auditable.fallback.enabled', config('auditable.fallback_enabled', true));
        if (!filter_var($enabled, FILTER_VALIDATE_BOOL)) {
            return;
        }

        try {
            DB::table(config('auditable.fallback.table', 'failed_audit_events'))->insert([
                'payload' => json_encode($payload),
                'failed_at' => now(),
                'retry_after' => now()->addMinutes((int) config('auditable.fallback.retry_after_minutes', 5)),
                'attempts' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::emergency('Audit fallback storage also failed', [
                'error' => $e->getMessage(),
            ]);
        }
    }

}