<?php

namespace Uzapoint\Auditable\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

class ProcessAuditEvents implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $backoff = 10;
    public int $timeout = 30;
    public bool $deleteWhenMissingModels = false;

    public function __construct(private array $payload) {}

    public function handle()
    {
        $table = config('auditable.table', 'activity_logs');

        DB::table($table)->insert([
            'uuid' => $this->payload['meta']['message_id'] ?? \Illuminate\Support\Str::uuid(),
            'event' => $this->payload['event'],
            'description' => $this->payload['description'],
            'log_name' => $this->payload['log_name'] ?? 'default',
            'subject_type' => $this->payload['subject_type'],
            'subject_id' => $this->payload['subject_id'],
            'causer_type' => $this->payload['causer_type'],
            'causer_id' => $this->payload['causer_id'],
            'causer_name' => $this->payload['causer_name'] ?? null,
            'causer_email' => $this->payload['causer_email'] ?? null,
            'changes' => json_encode($this->payload['changes'] ?? null),
            'properties' => json_encode(array_merge(
                $this->payload['properties'] ?? [],
                [
                    'trace_id' => $this->payload['trace_context']['trace_id'] ?? null,
                    'span_id' => $this->payload['trace_context']['span_id'] ?? null,
                    'published_at' => $this->payload['meta']['published_at'] ?? null,
                    'source_service' => $this->payload['meta']['publisher_service'] ?? null,
                    'source_environment' => $this->payload['meta']['publisher_environment'] ?? null,
                    'source_host' => $this->payload['meta']['publisher_host'] ?? null,
                    'source_version' => $this->payload['meta']['publisher_version'] ?? null,
                ]
            )),
            'source_service' => $this->payload['meta']['publisher_service'] ?? 'unknown',
            'source_environment' => $this->payload['meta']['publisher_environment'] ?? config('app.env'),
            'source_host' => $this->payload['meta']['publisher_host'] ?? gethostname(),
            'batch_uuid' => $this->payload['meta']['batch_uuid'] ?? null,
            'created_at' => $this->payload['meta']['published_at'] ?? now(),
            'recorded_at' => now(),
        ]);
    }
}