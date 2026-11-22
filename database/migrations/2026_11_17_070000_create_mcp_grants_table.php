<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One row per connected AI client of a user: the permission level they chose at consent.
        // Deleting the row is how a connection is revoked.
        Schema::create('mcp_grants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('oauth_client_id')->constrained('oauth_clients')->cascadeOnDelete();
            $table->string('level', 16);
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'oauth_client_id']);
        });

        // The activity log keeps its rows (and the client name) after a grant is deleted.
        Schema::table('activity_logs', function (Blueprint $table) {
            $table->foreign('mcp_grant_id')->references('id')->on('mcp_grants')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('activity_logs', function (Blueprint $table) {
            $table->dropForeign(['mcp_grant_id']);
        });

        Schema::dropIfExists('mcp_grants');
    }
};
