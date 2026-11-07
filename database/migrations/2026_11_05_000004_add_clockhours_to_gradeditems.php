<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('gradeditems', function (Blueprint $table) {
            $table->integer('clockhours')->nullable()->after('gradeitem');
        });
    }

    public function down(): void
    {
        Schema::table('gradeditems', function (Blueprint $table) {
            $table->dropColumn('clockhours');
        });
    }
};
