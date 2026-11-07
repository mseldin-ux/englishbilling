<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('formsdata', function (Blueprint $table) {
            $table->id();
            $table->string('form_type', 64)->index();
            $table->unsignedInteger('student_id')->index();
            $table->foreign('student_id')->references('id')->on('students')->restrictOnDelete();
            $table->unsignedInteger('program_id')->nullable()->index();
            $table->foreign('program_id')->references('id')->on('programs')->restrictOnDelete();
            $table->string('status', 16)->default('draft')->index();
            $table->json('data');
            $table->unsignedInteger('revision')->default(1);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamps();
        });
        // No foreign key to formsdata: history must survive an admin deletion.
        Schema::create('formsdata_history', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('formsdata_id')->index();
            $table->unsignedInteger('revision');
            $table->string('action', 16);
            $table->string('status', 16);
            $table->json('data');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('user_name');
            $table->timestamp('created_at');
        });
        Schema::create('form_edit_locks', function (Blueprint $table) {
            $table->unsignedBigInteger('formsdata_id')->primary();
            $table->foreign('formsdata_id')->references('id')->on('formsdata')->cascadeOnDelete();
            $table->string('session_id', 64)->index();
            $table->foreign('session_id')->references('id')->on('staff_sessions')->cascadeOnDelete();
            $table->string('token', 64);
            $table->unsignedBigInteger('acquired_at');
            $table->unsignedBigInteger('last_activity');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('form_edit_locks');
        Schema::dropIfExists('formsdata_history');
        Schema::dropIfExists('formsdata');
    }
};
