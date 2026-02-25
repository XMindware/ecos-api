<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('prompt_events', function (Blueprint $table) {
            $table->id();
            $table->uuid('request_uuid')->index();
            $table->string('endpoint', 50);
            $table->string('event', 50);
            $table->foreignId('prompt_id')->nullable()->constrained()->nullOnDelete();
            $table->json('filters')->nullable();
            $table->json('context')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 1024)->nullable();
            $table->timestamps();

            $table->index(['endpoint', 'created_at']);
            $table->index(['prompt_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('prompt_events');
    }
};
