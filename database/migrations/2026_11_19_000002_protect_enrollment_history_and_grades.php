<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('enrollment_events', fn (Blueprint $table) => $table->timestamp('voided_at')->nullable());
        foreach (['formsdata', 'finalgrades'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->foreignId('enrollment_id')->nullable()->constrained('student_enrollments')->restrictOnDelete();
                $table->string('enrollment_key', 64)->default('legacy');
            });
        }
        Schema::table('finalgrades', function (Blueprint $table) {
            $table->unique(['studentid', 'program_id', 'enrollment_key'], 'finalgrades_enrollment_unique');
            $table->dropUnique(['studentid', 'program_id']);
        });
    }

    public function down(): void
    {
        Schema::table('finalgrades', function (Blueprint $table) {
            $table->unique(['studentid', 'program_id']);
            $table->dropUnique('finalgrades_enrollment_unique');
        });
        foreach (['formsdata', 'finalgrades'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->dropConstrainedForeignId('enrollment_id');
                $table->dropColumn('enrollment_key');
            });
        }
        Schema::table('enrollment_events', fn (Blueprint $table) => $table->dropColumn('voided_at'));
    }
};
