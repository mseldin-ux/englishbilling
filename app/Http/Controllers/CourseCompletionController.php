<?php

namespace App\Http\Controllers;

use App\Billing\PdfRenderer;
use App\Models\CourseCompletion;

/** Staff view of Course Completion submissions. Submissions are read-only. */
class CourseCompletionController extends Controller
{
    public function show(CourseCompletion $completion)
    {
        $completion->load('student', 'invitation.program');
        $others = CourseCompletion::where('student_id', $completion->student_id)->whereKeyNot($completion->id)
            ->orderByDesc('submitted_at')->orderByDesc('id')->get(['id', 'submitted_at', 'replaces_previous']);

        return view('completions.show', ['completion' => $completion, 'others' => $others,
            'labels' => CourseCompletionPortalController::LABELS]);
    }

    public function pdf(CourseCompletion $completion, PdfRenderer $renderer)
    {
        $completion->load('student', 'invitation.program');
        $name = 'course-completion-'.$completion->id.'-'.preg_replace('/[^A-Za-z0-9]+/', '-', $completion->student?->displayName() ?? 'learner');

        return response($renderer->renderView('completions.pdf', ['completion' => $completion,
            'labels' => CourseCompletionPortalController::LABELS]), 200, [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'attachment; filename="'.trim($name, '-').'.pdf"',
                'Cache-Control' => 'private, no-store',
            ]);
    }
}
