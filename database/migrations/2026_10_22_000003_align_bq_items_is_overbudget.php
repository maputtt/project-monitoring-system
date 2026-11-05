<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Revisi C1: is_overbudget = (realisasi ?? nilai nego) > effective_budget, sama dengan Sisa < 0. Hanya data, tanpa perubahan skema.
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('UPDATE bq_items SET is_overbudget = (COALESCE(actual_cost, negotiated_value) > effective_budget)');
    }

    public function down(): void
    {
        // Nilai lama diturunkan dari nilai nego saja dan tidak disimpan; tidak ada yang dipulihkan.
    }
};
