<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Passkey (Face ID/sidik jari) per perangkat + token login perangkat 30 hari. Bukan riwayat finansial, boleh hard delete.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('passkeys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('credential_id', 255)->unique(); // base64url
            $table->text('public_key'); // PEM
            $table->smallInteger('algorithm'); // nilai COSE bertanda: -7 (ES256), -257 (RS256)
            $table->unsignedInteger('sign_count')->default(0);
            $table->string('name', 100);
            $table->char('device_token_hash', 64)->nullable(); // sha256 hex
            $table->timestamp('device_expires_at')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('passkeys');
    }
};
