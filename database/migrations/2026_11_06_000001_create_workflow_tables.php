<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_templates', function (Blueprint $table) {
            $table->id();
            $table->string('key', 64)->unique();
            $table->string('subject', 255);
            $table->text('body');
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('workflow_runs', function (Blueprint $table) {
            $table->id();
            $table->string('workflow', 64);
            $table->string('subject_type', 32);
            $table->unsignedBigInteger('subject_id');
            $table->unsignedInteger('student_id')->nullable()->index();
            $table->foreign('student_id')->references('id')->on('students')->nullOnDelete();
            $table->unsignedInteger('program_id')->nullable();
            $table->foreign('program_id')->references('id')->on('programs')->nullOnDelete();
            // active, paused, completed, or cancelled.
            $table->string('status', 16)->default('active');
            // Key of the stage the run is waiting at (null when finished).
            $table->string('current_step', 64)->nullable();
            $table->text('pause_note')->nullable();
            $table->timestamp('paused_at')->nullable();
            $table->foreignId('paused_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['workflow', 'status']);
            // A workflow runs at most once for a given record.
            $table->unique(['workflow', 'subject_type', 'subject_id']);
            $table->index(['subject_type', 'subject_id']);
        });

        Schema::create('workflow_steps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workflow_run_id')->constrained('workflow_runs')->cascadeOnDelete();
            $table->string('step', 64);
            $table->unsignedTinyInteger('position');
            // upcoming, pending, sending, waiting, scheduled, done, failed, skipped, or cancelled.
            $table->string('status', 16)->default('upcoming')->index();
            $table->unsignedSmallInteger('attempts')->default(0);
            // When a scheduled stage or automatic retry is due.
            $table->timestamp('available_at')->nullable();
            $table->timestamp('reached_at')->nullable();
            // Email steps: what was sent.
            $table->json('recipients')->nullable();
            $table->string('subject', 255)->nullable();
            $table->text('body')->nullable();
            // Answers, uploaded file details, attachment names, and other stage output.
            $table->json('data')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['workflow_run_id', 'step']);
        });

        // Permanent history of every action on a run; stages may be redone after being sent back.
        Schema::create('workflow_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workflow_run_id')->constrained('workflow_runs')->cascadeOnDelete();
            $table->string('step', 64)->nullable();
            $table->string('action', 32);
            $table->text('note')->nullable();
            $table->json('data')->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['workflow_run_id', 'id']);
        });

        Schema::create('app_settings', function (Blueprint $table) {
            $table->string('key', 128)->primary();
            $table->json('value')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::table('programs', function (Blueprint $table) {
            $table->string('director_team_email', 255)->nullable();
        });

        Schema::create('course_completion_invitations', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('student_id')->index();
            $table->foreign('student_id')->references('id')->on('students')->restrictOnDelete();
            $table->unsignedInteger('program_id')->nullable();
            $table->foreign('program_id')->references('id')->on('programs')->nullOnDelete();
            $table->unsignedBigInteger('formsdata_id')->nullable()->index();
            $table->foreign('formsdata_id')->references('id')->on('formsdata')->nullOnDelete();
            $table->foreignId('workflow_run_id')->nullable()->unique()->constrained('workflow_runs')->nullOnDelete();
            // Only a hash is searchable; the encrypted copy lets staff resend or copy the same link.
            $table->char('token_hash', 64)->unique();
            $table->text('token_encrypted');
            $table->timestamp('expires_at');
            $table->timestamp('revoked_at')->nullable();
            $table->timestamp('last_opened_at')->nullable();
            $table->timestamps();
        });

        Schema::create('course_completions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invitation_id')->constrained('course_completion_invitations')->restrictOnDelete();
            $table->unsignedInteger('student_id')->index();
            $table->foreign('student_id')->references('id')->on('students')->restrictOnDelete();
            $table->timestamp('submitted_at');
            $table->boolean('replaces_previous');
            $table->string('cohort', 128);
            $table->string('instructor', 128);
            $table->string('certificate_name', 256);
            $table->string('mailing_address', 256);
            $table->boolean('address_in_us');
            $table->boolean('survey_completed');
            $table->date('expected_graduation_date');
            $table->text('additional_info')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('course_completions');
        Schema::dropIfExists('course_completion_invitations');
        Schema::table('programs', function (Blueprint $table) {
            $table->dropColumn('director_team_email');
        });
        Schema::dropIfExists('app_settings');
        Schema::dropIfExists('workflow_events');
        Schema::dropIfExists('workflow_steps');
        Schema::dropIfExists('workflow_runs');
        Schema::dropIfExists('email_templates');
    }
};
