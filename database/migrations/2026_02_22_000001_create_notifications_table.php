<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->uuid('uuid')->unique();
            $table->uuid('user_uuid')->index();
            $table->string('template_key', length: 120)->index();
            $table->json('channels');
            $table->json('variables');
            $table->enum('status', ['queued', 'sent', 'failed'])->default(value: 'queued')->index();
            $table->string('idempotency_key', 100)->nullable();
            $table->timestamp('scheduled_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
