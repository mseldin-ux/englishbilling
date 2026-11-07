<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('programs', function (Blueprint $table) {
            $table->string('course_completion_survey_url', 500)->nullable()->after('director_team_email');
            $table->string('employer_survey_url', 500)->nullable()->after('course_completion_survey_url');
        });
    }

    public function down(): void
    {
        Schema::table('programs', function (Blueprint $table) {
            $table->dropColumn(['course_completion_survey_url', 'employer_survey_url']);
        });
    }
};
