<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activity_logs', function (Blueprint $table) {
            $table->id();
            // Nullable: failed sign-ins for unknown emails have no user, and rows outlive deleted users.
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('user_email')->nullable();
            $table->string('user_role', 16)->nullable();
            $table->string('kind', 48);
            $table->string('status', 16);
            // Filled from M2 on; a plain column here, the foreign key is added with the grants table.
            $table->unsignedBigInteger('mcp_grant_id')->nullable();
            $table->string('client_name')->nullable();
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 512)->nullable();
            $table->string('method', 64)->nullable();
            $table->string('tool', 96)->nullable();
            $table->json('args_redacted')->nullable();
            $table->json('meta')->nullable();
            $table->text('error')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->timestamp('created_at')->useCurrent()->index();

            $table->index(['user_id', 'created_at']);
            $table->index(['kind', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_logs');
    }
};
