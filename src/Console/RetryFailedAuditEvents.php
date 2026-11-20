<?php

namespace Uzapoint\Auditable\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Uzapoint\EventBus\RabbitMQService;

/**
 * Replays audit events that could not be published and were stored in the
 * fallback table. The stored payload is already redacted and enriched, so it
 * is sent to the audit exchange unchanged.
 *
 * A failed retry waits longer each time (retry_after_minutes doubled per
 * attempt, at most a day). After max_attempts the row is left with its last
 * error for a person to look at, and an error is logged.
 */
class RetryFailedAuditEvents extends Command
{
    protected $signature = 'audit:retry-failed
        {--limit= : Rows to replay in one run (default auditable.fallback.retry_batch_size, 200)}
        {--max-attempts= : Attempts before a row is left alone (default auditable.fallback.max_attempts, 10)}';

    protected $description = 'Republish audit events stored in the fallback table after a publish failure';

    public function handle(RabbitMQService $rabbit): int
    {
        $table = config('auditable.fallback.table', 'failed_audit_events');
        $limit = (int) ($this->option('limit') ?? config('auditable.fallback.retry_batch_size', 200));
        $maxAttempts = (int) ($this->option('max-attempts') ?? config('auditable.fallback.max_attempts', 10));
        $baseDelay = max(1, (int) config('auditable.fallback.retry_after_minutes', 5));

        $rows = DB::table($table)
            ->whereNull('processed_at')
            ->where('retry_after', '<=', now())
            ->where('attempts', '<', $maxAttempts)
            ->orderBy('id')
            ->limit($limit)
            ->get();

        $sent = 0;
        foreach ($rows as $row) {
            $attempts = (int) $row->attempts + 1;

            try {
                $rabbit->publish(
                    exchange: 'audit.events',
                    routingKey: 'audit.events',
                    data: json_decode($row->payload, true, flags: JSON_THROW_ON_ERROR),
                );

                DB::table($table)->where('id', $row->id)->update([
                    'attempts' => $attempts,
                    'processed_at' => now(),
                    'error' => null,
                    'updated_at' => now(),
                ]);
                $sent++;
            } catch (\Throwable $e) {
                DB::table($table)->where('id', $row->id)->update([
                    'attempts' => $attempts,
                    'retry_after' => now()->addMinutes(min($baseDelay * 2 ** ($attempts - 1), 1440)),
                    'error' => $e->getMessage(),
                    'updated_at' => now(),
                ]);

                if ($attempts >= $maxAttempts) {
                    Log::error('Audit event left in the fallback table after its last retry', [
                        'failed_audit_event_id' => $row->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        $this->info("Republished {$sent} of {$rows->count()} failed audit events.");

        return self::SUCCESS;
    }
}
