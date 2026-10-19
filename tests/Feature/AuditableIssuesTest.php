<?php

namespace Uzapoint\Auditable\Tests\Feature;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Uzapoint\Auditable\Context\UserContextResolver;
use Uzapoint\Auditable\Exceptions\AuditException;
use Uzapoint\Auditable\Services\AuditPublisher;
use Uzapoint\Auditable\Tests\Fixtures\Widget;
use Uzapoint\Auditable\Tests\TestCase;

/**
 * The issues found in v1.0.3 while integrating services-microservice
 * (docs/mpesa-integration-plan.md, "uzapoint/auditable v1.0.3 issues found").
 */
class AuditableIssuesTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('k', 32)));
    }

    /** Issue 1: the `updated` handler's check was inverted, so updates were never audited. */
    public function test_issue_1_updates_are_audited(): void
    {
        $widget = Widget::create(['name' => 'A']);
        $this->rabbit->published = [];

        $widget->update(['name' => 'B']);

        $payload = $this->lastPublished();
        $this->assertSame('updated', $payload['event']);
        $this->assertSame(['name' => 'A'], $payload['changes']['old']);
        $this->assertSame(['name' => 'B'], $payload['changes']['new']);
    }

    /** Issue 1 (cont.): a disabled event is not published. */
    public function test_issue_1_a_disabled_update_event_is_not_published(): void
    {
        config(['auditable.events' => ['created']]);
        $widget = Widget::create(['name' => 'A']);
        $this->rabbit->published = [];

        $widget->update(['name' => 'B']);

        $this->assertSame([], $this->rabbit->published);
    }

    /** Issue 2: handleFallback read `auditable.fallback_enabled` instead of `auditable.fallback.enabled`. */
    public function test_issue_2_failed_publishes_go_to_the_fallback_table(): void
    {
        $this->rabbit->fail = true;

        app(AuditPublisher::class)->log('manual', 'Something happened', ['properties' => ['x' => 1]]);

        $row = DB::table('failed_audit_events')->sole();
        $this->assertSame('manual', json_decode($row->payload, true)['event']);
        $this->assertSame(0, (int) $row->attempts);
    }

    public function test_issue_2_the_fallback_can_be_switched_off(): void
    {
        config(['auditable.fallback.enabled' => false]);
        $this->rabbit->fail = true;

        app(AuditPublisher::class)->log('manual', 'Something happened');

        $this->assertSame(0, DB::table('failed_audit_events')->count());
    }

    /** Issue 3a: without an X-Person header, building the causer name read undefined keys and threw. */
    public function test_issue_3_gateway_headers_without_x_person_resolve(): void
    {
        $this->app->instance('request', Request::create('/x', 'POST', server: [
            'HTTP_X_PERSON_ID' => '7',
            'HTTP_X_USER_ID' => '3',
            'HTTP_X_BUSINESS_ID' => '42',
        ]));

        $context = UserContextResolver::resolve();

        $this->assertSame('7', $context['id']);
        $this->assertSame('42', $context['business_id']);
        $this->assertSame('gateway_header', $context['source']);
        $this->assertNull($context['name']);
    }

    public function test_issue_3_names_are_built_from_whatever_parts_exist(): void
    {
        $this->app->instance('request', Request::create('/x', 'POST', [
            'user_id' => '9',
            'person' => ['first_name' => 'Jane'],
        ]));

        $this->assertSame('Jane', UserContextResolver::resolve()['name']);
    }

    /** Issue 3b: with no request user, resolving ended at auth('api'), which throws without that guard. */
    public function test_issue_3_no_api_guard_falls_back_to_the_system_context(): void
    {
        $this->assertArrayNotHasKey('api', config('auth.guards'));

        $context = UserContextResolver::resolve();

        $this->assertSame('system', $context['source']);
        $this->assertSame('system', $context['name']);
    }

    /** Issue 4: updates sent decrypted original values of encrypted (and hidden) columns. */
    public function test_issue_4_encrypted_and_hidden_columns_never_leak(): void
    {
        $widget = Widget::create(['name' => 'A', 'secret_note' => 'old-secret-123']);
        $created = $this->lastPublished();

        $widget->update(['secret_note' => 'new-secret-456', 'name' => 'B']);
        $updated = $this->lastPublished();

        $widget->delete();
        $deleted = $this->lastPublished();

        foreach ([$created, $updated, $deleted] as $payload) {
            $json = json_encode($payload);
            $this->assertStringNotContainsString('old-secret-123', $json);
            $this->assertStringNotContainsString('new-secret-456', $json);
        }

        // The field is still reported as changed, without its value.
        $this->assertSame('***REDACTED***', $updated['changes']['new']['secret_note']);
        $this->assertSame('***REDACTED***', $updated['changes']['old']['secret_note']);
        $this->assertSame('B', $updated['changes']['new']['name']);
    }

    public function test_issue_4_sensitive_names_match_any_case_and_nested_keys(): void
    {
        app(AuditPublisher::class)->log('manual', 'x', [
            'changes' => ['new' => ['API_KEY' => 'k1', 'nested' => ['Password' => 'p1', 'client_secret' => 's1']]],
            'properties' => ['token' => 't1'],
        ]);

        $json = json_encode($this->lastPublished());
        foreach (['k1', 'p1', 's1', 't1'] as $secret) {
            $this->assertStringNotContainsString("\"{$secret}\"", $json);
        }
    }

    /** A failed publish must not store unredacted data in the fallback table. */
    public function test_fallback_rows_are_redacted_too(): void
    {
        $this->rabbit->fail = true;

        app(AuditPublisher::class)->log('manual', 'x', ['changes' => ['new' => ['password' => 'p1']]]);

        $this->assertStringNotContainsString('p1', DB::table('failed_audit_events')->value('payload'));
    }

    /** Issue 5: publishFailed() passed the context array where a Throwable is expected. */
    public function test_issue_5_publish_failed_builds_an_exception(): void
    {
        $e = AuditException::publishFailed('broker down', ['exchange' => 'audit.events']);

        $this->assertSame('Audit publish failed: broker down', $e->getMessage());
        $this->assertSame(['exchange' => 'audit.events'], $e->context);
    }

    /** Restored events carry the business like every other event. */
    public function test_queued_context_with_missing_keys_does_not_break_publishing(): void
    {
        UserContextResolver::set(['id' => 5, 'name' => 'Queue worker']);

        Widget::create(['name' => 'A'])->update(['name' => 'B']);

        $payload = $this->lastPublished();
        $this->assertSame(5, $payload['causer_id']);
        $this->assertNull($payload['business_id']);
        UserContextResolver::clear();
    }
}
