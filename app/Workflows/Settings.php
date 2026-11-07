<?php

namespace App\Workflows;

use App\Models\Program;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Admin-editable workflow settings stored in app_settings. Stage text overrides are stored
 * under "stage:{workflow}:{step}" as {title, instructions, labels: {field: label}}.
 */
class Settings
{
    public const DEFINITIONS = [
        'finance_email' => ['label' => 'Finance team', 'type' => 'emails', 'default' => 'financeteam@cgms.edu',
            'help' => 'Asked to clear each Adult Learner financially, and sent international mailing requests.'],
        'admin_team_email' => ['label' => 'Admin team', 'type' => 'emails', 'default' => 'adminteam@cgms.edu',
            'help' => 'Reminded to update the accreditation status sheets, told when the correction wait ends, and told when a workflow is complete.'],
        'graduate_info_recipients' => ['label' => 'Verified graduate information recipients', 'type' => 'emails', 'default' => '',
            'help' => 'Sent the verified graduate information once the credential is ordered. The workflow stops at that stage until at least one address is entered.'],
        'alumni_recipients' => ['label' => 'Alumni coordinator and marketing', 'type' => 'emails', 'default' => '',
            'help' => 'Asked to add each graduate to the alumni list. The workflow stops at that stage until at least one address is entered.'],
        'employer_survey_url' => ['label' => 'Default Employer Graduate Survey link', 'type' => 'url', 'default' => '',
            'help' => 'Sent to the graduate\'s employer when their program has no link of its own (see Programs below). The workflow stops at that stage until a link is available.'],
        'course_completion_survey_url' => ['label' => 'Default Course Completion Survey link', 'type' => 'url',
            'default' => 'https://www.surveymonkey.com/r/KSY8TKJ', 'help' => 'Shown to Adult Learners on the Course Completion Form and in their invitation when their program has no link of its own (see Programs below).'],
        'employment_form_url' => ['label' => 'Default Employment Question Form link', 'type' => 'url', 'default' => '',
            'help' => 'Emailed to each graduate one year after their Course Completion workflow completes, when their program has no link of its own (see Programs below). The stage stops until a link is available.'],
        'correction_wait_days' => ['label' => 'Correction waiting period (calendar days)', 'type' => 'days', 'default' => 5,
            'help' => 'Time given to the graduate to report a wrong name or address before the credential is mailed.'],
    ];

    public static function get(string $key): mixed
    {
        $definition = self::DEFINITIONS[$key] ?? throw new InvalidArgumentException("Unknown setting {$key}.");
        $row = DB::table('app_settings')->where('key', $key)->value('value');

        return $row === null ? $definition['default'] : json_decode($row, true);
    }

    /**
     * A survey link for a program: the program's own link when set, otherwise the default here.
     *
     * @param  'course_completion_survey_url'|'employer_survey_url'|'employment_form_url'  $key
     */
    public static function surveyUrl(string $key, ?Program $program): string
    {
        return trim((string) $program?->{$key}) ?: trim((string) self::get($key));
    }

    /** @return array<int, string> */
    public static function emails(string $key): array
    {
        return EmailStep::addresses((string) self::get($key));
    }

    public static function put(string $key, mixed $value, ?User $user): void
    {
        DB::table('app_settings')->updateOrInsert(['key' => $key],
            ['value' => json_encode($value), 'updated_by' => $user?->id, 'updated_at' => now(), 'created_at' => now()]);
    }

    /** @return array{title?: string, instructions?: string, labels?: array<string, string>} */
    public static function stage(string $workflow, string $step): array
    {
        $row = DB::table('app_settings')->where('key', "stage:{$workflow}:{$step}")->value('value');

        return $row === null ? [] : (json_decode($row, true) ?: []);
    }

    /** Saves stage text overrides; empty values fall back to the built-in text. */
    public static function putStage(string $workflow, string $step, array $override, ?User $user): void
    {
        $override = array_filter([
            'title' => trim((string) ($override['title'] ?? '')),
            'instructions' => trim(str_replace("\r\n", "\n", (string) ($override['instructions'] ?? ''))),
            'labels' => array_filter(array_map(fn ($label) => trim((string) $label), $override['labels'] ?? [])),
        ]);
        if ($override === []) {
            DB::table('app_settings')->where('key', "stage:{$workflow}:{$step}")->delete();

            return;
        }
        DB::table('app_settings')->updateOrInsert(['key' => "stage:{$workflow}:{$step}"],
            ['value' => json_encode($override), 'updated_by' => $user?->id, 'updated_at' => now(), 'created_at' => now()]);
    }
}
