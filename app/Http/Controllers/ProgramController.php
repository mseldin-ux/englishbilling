<?php

namespace App\Http\Controllers;

use App\Billing\ProgramStages;
use App\Billing\RecordVersion;
use App\Models\Program;
use App\Models\Student;
use App\Models\StudentEnrollment;
use App\Models\StudentStageEnrollment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ProgramController extends Controller
{
    public function index()
    {
        return view('programs.index', ['programs' => Program::orderBy('id')->get()->keyBy('id'),
            'hasLegacyStages' => StudentEnrollment::where('mode', 'staged')->where('program_stages', false)->whereNull('ended_on')->exists()]);
    }

    public function create()
    {
        return $this->form(new Program);
    }

    public function edit(Program $program)
    {
        return $this->form($program);
    }

    private function form(Program $program)
    {
        $programs = Program::orderBy('id')->get();
        $certificateValues = $programs->filter(fn ($row) => ! $row->staged && $row->isCertificateLeading())
            ->mapWithKeys(fn ($row) => [$row->id => collect(Program::SHARED_FIELDS)
                ->mapWithKeys(fn ($field) => [$field => $row->$field])->all()])->all();

        return view('programs.form', compact('program', 'programs', 'certificateValues'));
    }

    public function store(Request $request)
    {
        return $this->save($request, new Program);
    }

    public function update(Request $request, Program $program)
    {
        return $this->save($request, $program);
    }

    private function save(Request $request, Program $program)
    {
        $request->merge(['nextprog' => $request->input('nextprog') ?: null]);
        if ($request->filled('certificate_program_id')) {
            $request->replace($request->except(Program::SHARED_FIELDS));
        }
        $data = $request->validate([
            'version' => [$program->exists ? 'required' : 'nullable', 'string'],
            'program' => ['required', 'string', 'max:255'],
            'programdesc' => ['nullable', 'string', 'max:65000'],
            'servicedays' => ['nullable', 'integer', 'between:1,10000'],
            'type' => ['nullable', 'string', 'max:8'], 'shortname' => ['nullable', 'string', 'max:32'],
            'director_team_email' => ['nullable', 'string', 'max:255'],
            'course_completion_survey_url' => ['nullable', 'url:http,https', 'max:500'],
            'employer_survey_url' => ['nullable', 'url:http,https', 'max:500'],
            'employment_form_url' => ['nullable', 'url:http,https', 'max:500'],
            'staged' => ['nullable', 'boolean'], 'certleading' => ['nullable', 'boolean'],
            'nextprog' => ['nullable', 'integer', 'min:1', 'exists:programs,id'],
            'certificate_program_id' => ['nullable', 'integer', 'exists:programs,id'],
        ]);
        DB::transaction(function () use ($program, $data) {
            Program::orderBy('id')->lockForUpdate()->get();
            $locked = $program->exists ? Program::findOrFail($program->id) : $program;
            if ($locked->exists) {
                RecordVersion::check($locked, $data['version']);
                if ((string) $locked->servicedays !== (string) ($data['servicedays'] ?? null)
                    && Student::where('program_id', $locked->id)->whereNull('current_enrollment_id')->exists()) {
                    throw ValidationException::withMessages(['servicedays' => 'This duration is used by legacy learners. Changing it would change historical reports; create a new program instead.']);
                }
                if (StudentStageEnrollment::where('program_id', $locked->id)->exists()
                    && ((bool) $locked->staged !== (bool) ($data['staged'] ?? null)
                        || (int) $locked->certificate_program_id !== (int) ($data['certificate_program_id'] ?? null))) {
                    throw ValidationException::withMessages(['program' => 'An enrolled stage cannot change its staged flag or shared certificate program. Create a new program instead.']);
                }
            }
            unset($data['version']);
            $locked->forceFill($data)->save();
            ProgramStages::validate($locked);
        });

        return redirect()->route('programs.index')->with('status', 'Program saved. Existing agreed enrollment durations and next-stage links are unchanged.');
    }
}
