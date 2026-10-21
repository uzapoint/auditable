<?php

namespace Uzapoint\Auditable\Tests\Feature;

use Illuminate\Support\Facades\DB;
use Uzapoint\Auditable\Context\BatchContext;
use Uzapoint\Auditable\Context\UserContextResolver;
use Uzapoint\Auditable\Services\AuditPublisher;
use Uzapoint\Auditable\Tests\Fixtures\Widget;
use Uzapoint\Auditable\Tests\TestCase;

/** Audit events wait for the database transaction they were raised in. */
class PublishAfterCommitTest extends TestCase
{
    private ?string $batch = null;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('k', 32)));
    }

    public function test_outside_a_transaction_events_are_published_immediately(): void
    {
        Widget::create(['name' => 'A']);

        $this->assertSame('created', $this->lastPublished()['event']);
    }

    public function test_inside_a_transaction_events_wait_for_the_commit(): void
    {
        DB::transaction(function () {
            $widget = Widget::create(['name' => 'A']);
            $widget->update(['name' => 'B']);

            $this->assertSame([], $this->rabbit->published, 'Published before the commit.');
        });

        $this->assertSame(['created', 'updated'], array_column($this->rabbit->published, 'event'));
    }

    public function test_a_rolled_back_transaction_publishes_nothing(): void
    {
        try {
            DB::transaction(function () {
                Widget::create(['name' => 'A']);
                throw new \RuntimeException('rollback');
            });
        } catch (\RuntimeException) {
        }

        $this->assertSame([], $this->rabbit->published);
        $this->assertSame(0, DB::table('failed_audit_events')->count());
    }

    public function test_a_rolled_back_savepoint_drops_only_its_own_events(): void
    {
        DB::transaction(function () {
            Widget::create(['name' => 'kept']);

            try {
                DB::transaction(function () {
                    Widget::create(['name' => 'dropped']);
                    throw new \RuntimeException('inner rollback');
                });
            } catch (\RuntimeException) {
            }
        });

        $names = array_map(fn ($p) => $p['changes']['new']['name'], $this->rabbit->published);
        $this->assertSame(['kept'], $names);
    }

    public function test_user_and_batch_are_captured_when_the_change_happens(): void
    {
        DB::transaction(function () {
            UserContextResolver::set(['id' => 9, 'name' => 'Jane', 'business_id' => 5]);
            $batch = BatchContext::set();

            Widget::create(['name' => 'A']);

            // Both are typically cleared in a `finally` before the outer commit.
            UserContextResolver::clear();
            BatchContext::clear();

            $this->assertNotNull($batch);
            $this->batch = $batch;
        });

        $payload = $this->lastPublished();
        $this->assertSame(9, $payload['causer_id']);
        $this->assertSame('Jane', $payload['causer_name']);
        $this->assertSame($this->batch, $payload['meta']['batch_uuid']);
    }

    public function test_manual_logs_also_wait_for_the_commit(): void
    {
        DB::transaction(function () {
            app(AuditPublisher::class)->log('manual', 'Something happened');

            $this->assertSame([], $this->rabbit->published);
        });

        $this->assertSame('manual', $this->lastPublished()['event']);
    }

    public function test_a_failed_publish_after_commit_goes_to_the_fallback_table(): void
    {
        $this->rabbit->fail = true;

        DB::transaction(fn () => Widget::create(['name' => 'A']));

        $this->assertSame('created', json_decode(DB::table('failed_audit_events')->sole()->payload, true)['event']);
    }

    public function test_waiting_for_the_commit_can_be_switched_off(): void
    {
        config(['auditable.after_commit' => false]);

        DB::transaction(function () {
            Widget::create(['name' => 'A']);

            $this->assertSame('created', $this->lastPublished()['event']);
        });
    }
}
