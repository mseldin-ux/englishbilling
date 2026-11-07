<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staff_settings', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary();
            $table->unsignedInteger('idle_minutes');
        });
        DB::table('staff_settings')->insert(['id' => 1, 'idle_minutes' => 60]);
        Schema::create('staff_sessions', function (Blueprint $table) {
            $table->string('id', 64)->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('last_activity')->index();
            $table->boolean('revoked')->default(false);
        });
        Schema::create('student_edit_locks', function (Blueprint $table) {
            $table->unsignedInteger('student_id')->primary();
            $table->foreign('student_id')->references('id')->on('students')->cascadeOnDelete();
            $table->string('session_id', 64);
            $table->foreign('session_id')->references('id')->on('staff_sessions')->cascadeOnDelete();
            $table->string('token', 64);
            $table->unsignedBigInteger('acquired_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_edit_locks');
        Schema::dropIfExists('staff_sessions');
        Schema::dropIfExists('staff_settings');
    }
};
