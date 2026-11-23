<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Story B: agregat request per menit (UTC), dipangkas saat dashboard admin dibuka. Data operasional, boleh di-drop.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('request_metrics', function (Blueprint $table) {
            $table->dateTime('bucket_at')->primary();
            $table->unsignedInteger('request_count')->default(0);
            $table->unsignedBigInteger('duration_ms_sum')->default(0);
            $table->unsignedInteger('duration_ms_max')->default(0);
            $table->unsignedInteger('error_5xx_count')->default(0);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('request_metrics');
    }
};
