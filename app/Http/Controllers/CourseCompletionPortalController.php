<?php

namespace App\Http\Controllers;

use App\Models\CourseCompletion;
use App\Models\CourseCompletionInvitation;
use App\Models\FormData;
use App\Workflows\CourseCompletionWorkflow;
use App\Workflows\Settings;
use App\Workflows\Steps\Graduation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * The public Course Completion Form. Adult Learners have no login; the unguessable
 * link token identifies the invitation.
 */
class CourseCompletionPortalController extends Controller
{
    public const LABELS = [
        'replaces_previous' => 'Is this submission replacing or updating a previous submission?',
        'cohort' => 'Cohort',
        'instructor' => 'Instructor',
        'certificate_name' => 'Your name as you would like it to appear on your certificate',
        'mailing_address' => 'Please provide mailing address for certificate',
        'address_in_us' => 'Is this address within the United States? If no, you will be connected with Finance for international mailing payment.',
        'survey_completed' => 'Did you complete the short Course Completion Survey? It is at this link before your certificate is ordered. Answers are anonymous and are greatly appreciated by the faculty, director and administration team of CGMS.',
        'expected_graduation_date' => 'Your expected graduation date',
        'employment_status' => 'Are you currently:',
        'additional_info' => 'Any additional information you would like to provide',
    ];

    public function show(Request $request, string $token)
    {
        $invitation = $this->invitation($token);
        if (! $invitation->usable()) {
            return $this->unavailable($invitation);
        }
        $invitation->forceFill(['last_opened_at' => now()])->saveQuietly();
        $student = $invitation->student;
        $previous = $invitation->submissions()->latest('id')->first()
            ?? CourseCompletion::where('student_id', $student->id)->latest('id')->first();
        $values = $previous ? [
            'replaces_previous' => '1',
            'cohort' => $previous->cohort, 'instructor' => $previous->instructor,
            'certificate_name' => $previous->certificate_name, 'mailing_address' => $previous->mailing_address,
            'address_in_us' => $previous->address_in_us ? '1' : '0', 'survey_completed' => $previous->survey_completed ? '1' : '0',
            'expected_graduation_date' => $previous->expected_graduation_date, 'employment_status' => $previous->employment_status,
            'additional_info' => $previous->additional_info,
        ] : [
            'replaces_previous' => '0',
            'cohort' => $student->cohort, 'instructor' => null, 'certificate_name' => $student->displayName(),
            'mailing_address' => Graduation::address($student), 'address_in_us' => null, 'survey_completed' => null,
            'expected_graduation_date' => $this->graduationDate($invitation), 'employment_status' => null, 'additional_info' => null,
        ];
        foreach ($values as $field => $value) {
            $values[$field] = old($field, $value);
        }

        return $this->secure(response()->view('portal.course-completion', ['invitation' => $invitation, 'student' => $student,
            'values' => $values, 'previous' => $previous, 'token' => $token, 'labels' => self::LABELS,
            'surveyUrl' => Settings::surveyUrl('course_completion_survey_url', $invitation->program)]));
    }

    public function store(Request $request, string $token)
    {
        $invitation = $this->invitation($token);
        if (! $invitation->usable()) {
            return $this->unavailable($invitation);
        }
        foreach (['cohort', 'instructor', 'certificate_name', 'mailing_address', 'additional_info'] as $field) {
            if (is_string($request->input($field))) {
                $request->merge([$field => trim($request->input($field)) === '' ? null : trim($request->input($field))]);
            }
        }
        $data = $request->validate([
            'replaces_previous' => ['required', Rule::in(['0', '1'])],
            'cohort' => ['required', 'string', 'max:128'],
            'instructor' => ['required', 'string', 'max:128'],
            'certificate_name' => ['required', 'string', 'max:256'],
            'mailing_address' => ['required', 'string', 'max:256'],
            'address_in_us' => ['required', Rule::in(['0', '1'])],
            'survey_completed' => ['required', Rule::in(['1'])],
            'expected_graduation_date' => ['required', 'date_format:Y-m-d', 'after:2000-01-01', 'before:2200-01-01'],
            'employment_status' => ['required', Rule::in(array_keys(CourseCompletion::EMPLOYMENT))],
            'additional_info' => ['nullable', 'string', 'max:65000'],
        ], [
            'employment_status.required' => 'Tell us whether you are currently employed.',
            'employment_status.in' => 'Choose one of the employment options.',
            'survey_completed.in' => 'Please complete the Course Completion Survey, then answer Yes.',
            'survey_completed.required' => 'Please complete the Course Completion Survey, then answer Yes.',
            'replaces_previous.required' => 'Tell us whether this replaces or updates a previous submission.',
            'address_in_us.required' => 'Tell us whether the address is within the United States.',
        ], [
            'cohort' => 'cohort', 'instructor' => 'instructor', 'certificate_name' => 'name for your certificate',
            'mailing_address' => 'mailing address', 'expected_graduation_date' => 'expected graduation date',
            'additional_info' => 'additional information',
        ]);
        $completion = DB::transaction(function () use ($invitation, $data, $request) {
            return CourseCompletion::create([...$data, 'invitation_id' => $invitation->id, 'student_id' => $invitation->student_id,
                'replaces_previous' => $data['replaces_previous'] === '1', 'address_in_us' => $data['address_in_us'] === '1',
                'survey_completed' => true, 'submitted_at' => now(), 'ip_address' => $request->ip()]);
        });
        dispatch(fn () => CourseCompletionWorkflow::completionSubmitted($completion))->afterResponse();

        return redirect()->route('portal.completion.show', $token)->with('submitted', true);
    }

    private function invitation(string $token): CourseCompletionInvitation
    {
        $invitation = CourseCompletionInvitation::findByToken($token);
        abort_if($invitation === null, 404, 'This Course Completion link is not valid. Please check that you copied the whole link from your email.');

        return $invitation;
    }

    private function unavailable(CourseCompletionInvitation $invitation)
    {
        return $this->secure(response()->view('portal.unavailable', ['expired' => $invitation->revoked_at === null], 410));
    }

    private function secure($response)
    {
        // The token is in the URL, so never leak it to the survey site or other links.
        return $response->header('Referrer-Policy', 'no-referrer')->header('X-Robots-Tag', 'noindex, nofollow')
            ->header('Cache-Control', 'private, no-store');
    }

    private function graduationDate(CourseCompletionInvitation $invitation): ?string
    {
        $date = $invitation->formsdata_id ? (FormData::find($invitation->formsdata_id)?->data['graduation_date'] ?? null) : null;

        return is_string($date) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) ? $date : null;
    }
}
