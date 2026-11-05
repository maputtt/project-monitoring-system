<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Baseline nilai kontrak SPK (Paket 3). contract_value menjadi nilai kontrak berjalan setelah koreksi.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->decimal('initial_contract_value', 18, 2)->nullable()->after('contract_value');
        });

        DB::statement('UPDATE projects SET initial_contract_value = contract_value');

        DB::statement('ALTER TABLE projects MODIFY initial_contract_value DECIMAL(18,2) NOT NULL');
        DB::statement('ALTER TABLE projects ADD CONSTRAINT chk_projects_initial_contract_value CHECK (initial_contract_value >= 0)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE projects DROP CHECK chk_projects_initial_contract_value');

        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn('initial_contract_value');
        });
    }
};
