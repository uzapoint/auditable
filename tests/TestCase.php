<?php

namespace Uzapoint\Auditable\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase as Orchestra;
use Uzapoint\Auditable\AuditServiceProvider;
use Uzapoint\EventBus\RabbitMQService;

abstract class TestCase extends Orchestra
{
    /** Records what would have been sent to RabbitMQ, or fails on demand. */
    protected FakeRabbitMQ $rabbit;

    protected function getPackageProviders($app): array
    {
        return [AuditServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->rabbit = new FakeRabbitMQ;
        $this->app->instance(RabbitMQService::class, $this->rabbit);

        (require __DIR__.'/../database/migrations/2026_07_15_000001_create_failed_audit_events_table.php')->up();

        Schema::create('widgets', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('status')->nullable();
            $table->text('secret_note')->nullable();
            $table->string('api_key')->nullable();
            $table->timestamps();
        });
    }

    /** @return array<string, mixed> The last payload published. */
    protected function lastPublished(): array
    {
        $this->assertNotEmpty($this->rabbit->published, 'Nothing was published.');

        return end($this->rabbit->published);
    }
}

class FakeRabbitMQ extends RabbitMQService
{
    /** @var array<int, array<string, mixed>> */
    public array $published = [];

    public bool $fail = false;

    public function publish(string $exchange, string $routingKey, array $data, array $headers = []): void
    {
        if ($this->fail) {
            throw new \RuntimeException('RabbitMQ is down');
        }

        $this->published[] = $data;
    }
}
