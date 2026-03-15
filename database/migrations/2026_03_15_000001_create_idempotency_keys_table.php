<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('idempotency_keys', function (Blueprint $table) {
            $table->id();
            $table->char('uuid', 36)->unique();
            $table->char('user_uuid', 36);
            $table->string('idempotency_key', 100);
            $table->string('request_hash', 64);
            $table->char('notification_uuid', 36);
            $table->timestamps();

            $table->unique(['user_uuid', 'idempotency_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('idempotency_keys');
    }
};
