<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('course_completions', function (Blueprint $table) {
            $table->string('employment_status', 32)->nullable()->after('expected_graduation_date');
        });
    }

    public function down(): void
    {
        Schema::table('course_completions', function (Blueprint $table) {
            $table->dropColumn('employment_status');
        });
    }
};
