<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('employees')) {
            return;
        }
        Schema::table('employees', function (Blueprint $t) {
            if (! Schema::hasColumn('employees', 'esic_no')) {
                $t->string('esic_no')->nullable();
            }
            if (! Schema::hasColumn('employees', 'marital_status')) {
                $t->string('marital_status')->nullable();
            }
            if (! Schema::hasColumn('employees', 'employment_type')) {
                $t->string('employment_type')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $t) {
            foreach (['esic_no', 'employment_type', 'marital_status'] as $c) {
                if (Schema::hasColumn('employees', $c)) {
                    $t->dropColumn($c);
                }
            }
        });
    }
};
