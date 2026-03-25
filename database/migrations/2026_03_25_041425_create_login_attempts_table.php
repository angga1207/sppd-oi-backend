<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('login_attempts', function (Blueprint $table) {
            $table->id();
            $table->string('username');
            $table->string('ip_address', 45);
            $table->integer('attempts')->default(0);
            $table->boolean('is_blocked')->default(false);
            $table->timestamp('blocked_until')->nullable();
            $table->timestamp('last_attempt_at')->nullable();
            $table->timestamps();

            $table->index(['username', 'ip_address']);
            $table->index('is_blocked');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('login_attempts');
    }
};
