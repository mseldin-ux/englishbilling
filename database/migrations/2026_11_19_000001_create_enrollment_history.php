<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('program_stage_definitions', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('program_id');
            $table->foreign('program_id')->references('id')->on('programs')->restrictOnDelete();
            $table->unsignedTinyInteger('stage');
            $table->unsignedInteger('service_days');
            $table->unique(['program_id', 'stage']);
        });
        Schema::create('student_enrollments', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('student_id');
            $table->foreign('student_id')->references('id')->on('students')->restrictOnDelete();
            $table->unsignedInteger('program_id')->nullable();
            $table->foreign('program_id')->references('id')->on('programs')->restrictOnDelete();
            $table->string('mode', 16);
            $table->date('started_on')->nullable();
            $table->date('ended_on')->nullable();
            $table->string('status', 24)->default('active');
            $table->unsignedInteger('service_days')->nullable();
            $table->json('legacy_context')->nullable();
            $table->timestamps();
        });
        Schema::create('student_stage_enrollments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('enrollment_id')->constrained('student_enrollments')->restrictOnDelete();
            $table->unsignedTinyInteger('stage');
            $table->date('started_on');
            $table->date('completed_on')->nullable();
            $table->date('ended_on')->nullable();
            $table->string('status', 24)->default('active');
            $table->unsignedInteger('service_days');
            $table->timestamps();
        });
        Schema::create('enrollment_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('enrollment_id')->constrained('student_enrollments')->restrictOnDelete();
            $table->foreignId('stage_enrollment_id')->nullable()->constrained('student_stage_enrollments')->restrictOnDelete();
            $table->string('action', 24);
            $table->date('effective_on');
            $table->json('details')->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at');
        });
        Schema::table('students', function (Blueprint $table) {
            $table->foreignId('current_enrollment_id')->nullable()->constrained('student_enrollments')->restrictOnDelete();
            $table->unsignedInteger('enrollment_revision')->default(0);
        });
        foreach (['studenttransactions', 'invoices', 'studentpaymentplans', 'anticipatedpayment', 'loas'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->foreignId('enrollment_id')->nullable()->constrained('student_enrollments')->restrictOnDelete();
                $table->foreignId('stage_enrollment_id')->nullable()->constrained('student_stage_enrollments')->restrictOnDelete();
            });
        }
        Schema::table('studenttransactions', function (Blueprint $table) {
            $table->string('recognition', 16)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('studenttransactions', fn (Blueprint $table) => $table->dropColumn('recognition'));
        foreach (['studenttransactions', 'invoices', 'studentpaymentplans', 'anticipatedpayment', 'loas'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->dropConstrainedForeignId('stage_enrollment_id');
                $table->dropConstrainedForeignId('enrollment_id');
            });
        }
        Schema::table('students', fn (Blueprint $table) => $table->dropConstrainedForeignId('current_enrollment_id'));
        Schema::table('students', fn (Blueprint $table) => $table->dropColumn('enrollment_revision'));
        Schema::dropIfExists('enrollment_events');
        Schema::dropIfExists('student_stage_enrollments');
        Schema::dropIfExists('student_enrollments');
        Schema::dropIfExists('program_stage_definitions');
    }
};
