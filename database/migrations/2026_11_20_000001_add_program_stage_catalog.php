<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('programs', function (Blueprint $table) {
            $table->boolean('staged')->nullable();
            $table->boolean('certleading')->nullable();
            $table->unsignedInteger('nextprog')->nullable();
            $table->unsignedInteger('certificate_program_id')->nullable();
            $table->foreign('certificate_program_id')->references('id')->on('programs')->restrictOnDelete();
        });
        DB::table('programs')->where('type', 'cert')->update(['certleading' => true]);
        Schema::table('student_enrollments', function (Blueprint $table) {
            $table->boolean('program_stages')->default(false);
        });
        Schema::table('student_stage_enrollments', function (Blueprint $table) {
            $table->unsignedInteger('program_id')->nullable();
            $table->foreign('program_id')->references('id')->on('programs')->restrictOnDelete();
            $table->unsignedInteger('next_program_id')->nullable();
            $table->foreign('next_program_id')->references('id')->on('programs')->restrictOnDelete();
            $table->string('program_name')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('student_stage_enrollments', function (Blueprint $table) {
            $table->dropForeign(['program_id']);
            $table->dropForeign(['next_program_id']);
            $table->dropColumn(['program_id', 'next_program_id', 'program_name']);
        });
        Schema::table('student_enrollments', fn (Blueprint $table) => $table->dropColumn('program_stages'));
        Schema::table('programs', function (Blueprint $table) {
            $table->dropForeign(['certificate_program_id']);
            $table->dropColumn(['staged', 'certleading', 'nextprog', 'certificate_program_id']);
        });
    }
};
