<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('telegram_connections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('telegram_user_id')->unique();
            $table->string('telegram_chat_id')->unique();
            $table->string('telegram_username')->nullable();
            $table->timestamp('connected_at');
            $table->timestamps();
        });
        Schema::create('telegram_connection_codes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->char('code_hash', 64)->unique();
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'expires_at']);
        });
        Schema::create('telegram_states', function (Blueprint $table) {
            $table->id();
            $table->string('telegram_user_id')->unique();
            $table->string('state')->nullable();
            $table->json('data')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });
        Schema::create('telegram_updates', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('update_id')->unique();
            $table->timestamp('processed_at');
        });
        Schema::table('trades', function (Blueprint $table) {
            $table->string('idempotency_key')->nullable()->unique()->after('talaboard_reference');
        });
    }

    public function down(): void
    {
        Schema::table('trades', fn (Blueprint $table) => $table->dropUnique(['idempotency_key']));
        Schema::table('trades', fn (Blueprint $table) => $table->dropColumn('idempotency_key'));
        Schema::dropIfExists('telegram_updates');
        Schema::dropIfExists('telegram_states');
        Schema::dropIfExists('telegram_connection_codes');
        Schema::dropIfExists('telegram_connections');
    }
};
