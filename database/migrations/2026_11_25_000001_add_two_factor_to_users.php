<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Encrypted with APP_KEY by the model cast. Set while a setup is in progress, usable only once confirmed.
            $table->text('two_factor_secret')->nullable()->after('remember_token');
            // Two-factor sign-in applies only from this moment: until the user proved their app works, it is off.
            $table->timestamp('two_factor_confirmed_at')->nullable()->after('two_factor_secret');
            // The 30-second step of the last code accepted, so the same code cannot be used twice.
            $table->unsignedBigInteger('two_factor_last_step')->nullable()->after('two_factor_confirmed_at');
        });

        Schema::create('two_factor_recovery_codes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // Only a keyed hash of the code is stored, so a copy of the database holds no working code.
            $table->char('code_hash', 64);
            $table->timestamp('used_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'code_hash']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('two_factor_recovery_codes');

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['two_factor_secret', 'two_factor_confirmed_at', 'two_factor_last_step']);
        });
    }
};
