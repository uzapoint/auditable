<?php

namespace Uzapoint\Auditable\Tests\Feature;

use Illuminate\Auth\GenericUser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Uzapoint\Auditable\Context\UserContextResolver;
use Uzapoint\Auditable\Services\AuditPublisher;
use Uzapoint\Auditable\Tests\Fixtures\Widget;
use Uzapoint\Auditable\Tests\TestCase;

class UserContextAndRedactionTest extends TestCase
{
    public function test_a_defined_guard_still_provides_the_causer(): void
    {
        config(['auth.guards.api' => ['driver' => 'session', 'provider' => 'users']]);
        Auth::guard('api')->setUser(new GenericUser(['id' => 11, 'first_name' => 'Ann', 'last_name' => 'Wan']));

        $context = UserContextResolver::resolve();

        $this->assertSame(11, $context['id']);
        $this->assertSame('Ann Wan', $context['name']);
        $this->assertSame('local_auth', $context['source']);
    }

    public function test_the_x_person_header_is_used_when_sent(): void
    {
        $person = base64_encode(json_encode(['name' => 'Jane Doe', 'email' => 'j@example.test']));
        $this->app->instance('request', Request::create('/x', 'POST', server: [
            'HTTP_X_PERSON_ID' => '7',
            'HTTP_X_PERSON' => $person,
        ]));

        $context = UserContextResolver::resolve();

        $this->assertSame('Jane Doe', $context['name']);
        $this->assertSame('j@example.test', $context['email']);
    }

    public function test_a_malformed_x_person_header_is_ignored(): void
    {
        $this->app->instance('request', Request::create('/x', 'POST', server: [
            'HTTP_X_PERSON_ID' => '7',
            'HTTP_X_PERSON' => '%%%not-base64%%%',
        ]));

        $context = UserContextResolver::resolve();

        $this->assertSame('7', $context['id']);
        $this->assertNull($context['name']);
    }

    public function test_every_context_has_every_key(): void
    {
        foreach ([UserContextResolver::resolve(), UserContextResolver::forMessage()] as $context) {
            foreach (['id', 'name', 'email', 'type', 'business_id', 'terminal_id', 'person', 'roles'] as $key) {
                $this->assertArrayHasKey($key, $context);
            }
        }
    }

    public function test_models_can_exclude_more_fields(): void
    {
        $model = new class extends Widget
        {
            protected array $auditExclude = ['api_key'];
        };

        $model->fill(['name' => 'A', 'api_key' => 'plain-key-789'])->save();

        $this->assertSame(AuditPublisher::REDACTED, $this->lastPublished()['changes']['new']['api_key']);
        $this->assertStringNotContainsString('plain-key-789', json_encode($this->rabbit->published));
    }

    public function test_events_carry_the_business_from_the_gateway(): void
    {
        $this->app->instance('request', Request::create('/x', 'POST', server: [
            'HTTP_X_PERSON_ID' => '7',
            'HTTP_X_BUSINESS_ID' => '42',
        ]));

        Widget::create(['name' => 'A']);

        $payload = $this->lastPublished();
        $this->assertSame('42', $payload['business_id']);
        $this->assertSame('7', $payload['causer_id']);
        $this->assertSame('gateway_header', $payload['properties']['source']);
    }
}
