<?php

namespace Uzapoint\Auditable\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use OpenTelemetry\API\Trace\Span;
use Uzapoint\Auditable\Context\BatchContext;
use Uzapoint\EventBus\RabbitMQService;

class AuditPublisher
{
    /**
     * @throws \Exception
     */
    public function publish(array $payload): void
    {
        try {
            $payload = $this->enrichPayload($payload);
            $payload = $this->sanitizePayload($payload);

            app(RabbitMQService::class)->publish(
                exchange: 'audit.events',
                routingKey: 'audit.events',
                data: $payload
            );
        } catch (\Exception $e) {
            $this->handleFallback($payload);
            Log::error("Failed to publish audit event: " . $e->getMessage());
        }
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

    private function sanitizePayload(array $payload): array
    {
        $sensitive = config('auditable.sensitive_fields', []);

        $scrub = function ($data) use ($sensitive, &$scrub) {
            if (!is_array($data)) return $data;

            foreach ($data as $key => $value) {
                if (in_array($key, $sensitive, true)) {
                    $data[$key] = '***REDACTED***';
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
        if (!config('auditable.fallback_enabled')) {
            return;
        }

        try {
            DB::table(config('auditable.fallback.table'))->insert([
                'payload' => json_encode($payload),
                'failed_at' => now(),
                'retry_after' => now()->addMinutes(config('auditable.fallback.retry_after_minutes')),
                'attempts' => 0,
            ]);
        } catch (\Throwable $e) {
            Log::emergency('Audit fallback storage also failed', [
                'error' => $e->getMessage(),
            ]);
        }
    }

}