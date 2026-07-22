<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('failed_audit_events', function (Blueprint $table) {
            $table->id();
            $table->json('payload');
            $table->timestamp('failed_at');
            $table->timestamp('retry_after');
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('processed_at')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();

            $table->index(['retry_after', 'attempts']);
            $table->index('processed_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('failed_audit_events');
    }
};