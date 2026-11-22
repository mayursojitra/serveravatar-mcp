<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One ServerAvatar API token per user (FR-4). The token itself is encrypted by the model cast
        // with the app key; only its last four characters are kept readable.
        Schema::create('sa_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->text('token');
            $table->string('last4', 4);
            // [{id, name}] of the organizations the token can see. Never member lists or emails.
            $table->json('organizations');
            $table->timestamp('verified_at')->nullable();
            // Set when ServerAvatar later rejects the token; cleared by the next successful check.
            $table->string('last_error')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sa_tokens');
    }
};
