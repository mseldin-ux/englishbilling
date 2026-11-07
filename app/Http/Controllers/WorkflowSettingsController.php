<?php

namespace App\Http\Controllers;

use App\Models\Program;
use App\Workflows\AcceptsInput;
use App\Workflows\EmailStep;
use App\Workflows\ManualStep;
use App\Workflows\Settings;
use App\Workflows\Workflows;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/** Admin page for workflow recipients, links, waiting periods, program team emails and stage texts. */
class WorkflowSettingsController extends Controller
{
    public function edit()
    {
        $values = [];
        foreach (array_keys(Settings::DEFINITIONS) as $key) {
            $values[$key] = Settings::get($key);
        }
        $stages = [];
        foreach (array_keys(Workflows::all()) as $workflow) {
            foreach (Workflows::steps($workflow) as $key => $step) {
                if ($step instanceof ManualStep) {
                    $stages[$workflow][$key] = ['step' => $step, 'override' => Settings::stage($workflow, $key)];
                }
            }
        }

        return view('workflows.settings', ['definitions' => Settings::DEFINITIONS, 'values' => $values,
            'programs' => Program::orderBy('programdesc')->get(['id', 'programdesc', 'shortname', 'type', 'director_team_email', 'course_completion_survey_url', 'employer_survey_url', 'employment_form_url']),
            'stages' => $stages, 'workflows' => Workflows::all()]);
    }

    public function update(Request $request)
    {
        $rules = [];
        foreach (Settings::DEFINITIONS as $key => $definition) {
            $rules["settings.{$key}"] = match ($definition['type']) {
                'emails' => ['nullable', 'string', 'max:2000'],
                'url' => ['nullable', 'url:http,https', 'max:500'],
                'days' => ['required', 'integer', 'min:0', 'max:60'],
            };
        }
        $data = $request->validate([...$rules,
            'programs' => ['array'], 'programs.*' => ['array'], 'programs.*.director_team_email' => ['nullable', 'string', 'max:255'],
            'programs.*.course_completion_survey_url' => ['nullable', 'url:http,https', 'max:500'],
            'programs.*.employer_survey_url' => ['nullable', 'url:http,https', 'max:500'],
            'programs.*.employment_form_url' => ['nullable', 'url:http,https', 'max:500'],
            'stages' => ['array'], 'stages.*.*.title' => ['nullable', 'string', 'max:200'],
            'stages.*.*.instructions' => ['nullable', 'string', 'max:5000'], 'stages.*.*.labels.*' => ['nullable', 'string', 'max:500'],
        ], [], ['settings.correction_wait_days' => 'correction waiting period', 'programs.*.course_completion_survey_url' => 'Course Completion Survey link',
            'programs.*.employer_survey_url' => 'Employer Graduate Survey link', 'programs.*.employment_form_url' => 'Employment Question Form link']);
        $errors = [];
        foreach (Settings::DEFINITIONS as $key => $definition) {
            if ($definition['type'] === 'emails') {
                $errors += $this->invalidAddresses("settings.{$key}", $data['settings'][$key] ?? '');
            }
        }
        foreach ($data['programs'] ?? [] as $id => $values) {
            $errors += $this->invalidAddresses("programs.{$id}.director_team_email", $values['director_team_email'] ?? '');
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
        $user = $request->user();
        DB::transaction(function () use ($data, $user) {
            foreach (Settings::DEFINITIONS as $key => $definition) {
                $value = $data['settings'][$key] ?? null;
                Settings::put($key, match ($definition['type']) {
                    'emails' => implode(', ', EmailStep::addresses($value)),
                    'days' => (int) $value,
                    default => trim((string) $value),
                }, $user);
            }
            foreach ($data['programs'] ?? [] as $id => $values) {
                DB::table('programs')->where('id', (int) $id)->update([
                    'director_team_email' => implode(', ', EmailStep::addresses($values['director_team_email'] ?? null)) ?: null,
                    'course_completion_survey_url' => trim((string) ($values['course_completion_survey_url'] ?? '')) ?: null,
                    'employer_survey_url' => trim((string) ($values['employer_survey_url'] ?? '')) ?: null,
                    'employment_form_url' => trim((string) ($values['employment_form_url'] ?? '')) ?: null,
                ]);
            }
            $known = [];
            foreach (array_keys(Workflows::all()) as $workflow) {
                foreach (Workflows::steps($workflow) as $key => $step) {
                    if ($step instanceof AcceptsInput && $step instanceof ManualStep) {
                        $known[$workflow][$key] = $step;
                    }
                }
            }
            foreach ($data['stages'] ?? [] as $workflow => $steps) {
                foreach ($steps as $key => $override) {
                    $step = $known[$workflow][$key] ?? null;
                    if ($step === null) {
                        continue;
                    }
                    // Only store text that differs from the built-in wording.
                    $labels = [];
                    foreach ($step->defaultFields() as $field => $definition) {
                        $label = trim((string) ($override['labels'][$field] ?? ''));
                        if ($label !== '' && $label !== $definition['label']) {
                            $labels[$field] = $label;
                        }
                    }
                    $title = trim((string) ($override['title'] ?? ''));
                    $instructions = trim(str_replace("\r\n", "\n", (string) ($override['instructions'] ?? '')));
                    Settings::putStage($workflow, $key, [
                        'title' => $title !== $step->defaultTitle() ? $title : '',
                        'instructions' => $instructions !== (string) $step->defaultInstructions() ? $instructions : '',
                        'labels' => $labels,
                    ], $user);
                }
            }
        });
        Log::info('Workflow settings updated.', ['user_id' => $user->id]);
        Workflows::retryFailed($user);

        return redirect()->route('workflows.settings.edit')->with('status', 'Workflow settings saved.');
    }

    /** @return array<string, string> */
    private function invalidAddresses(string $field, string $value): array
    {
        $bad = [];
        foreach (preg_split('/[\s,;]+/', $value, -1, PREG_SPLIT_NO_EMPTY) as $address) {
            if (EmailStep::addresses($address) === []) {
                $bad[] = '"'.$address.'"';
            }
        }

        return $bad === [] ? [] : [$field => 'Not a valid email address: '.implode(', ', $bad).'. Separate addresses with commas.'];
    }
}
