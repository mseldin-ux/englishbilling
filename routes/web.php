<?php

use App\Billing\BillingTables;
use App\Http\Controllers\BillingRecordController;
use App\Http\Controllers\CourseCompletionController;
use App\Http\Controllers\CourseCompletionPortalController;
use App\Http\Controllers\DocumentController;
use App\Http\Controllers\EmailTemplateController;
use App\Http\Controllers\FinalGradesController;
use App\Http\Controllers\FormsController;
use App\Http\Controllers\LockController;
use App\Http\Controllers\PaymentPlanController;
use App\Http\Controllers\RecordsController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\SessionController;
use App\Http\Controllers\StudentController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\WorkflowController;
use App\Http\Controllers\WorkflowSettingsController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::get('/login', [SessionController::class, 'create'])->name('login');
    Route::post('/login', [SessionController::class, 'store'])->middleware('throttle:6,1');
});

// Adult Learner portal: no login; the link token identifies the invitation.
Route::middleware('throttle:30,1')->group(function () {
    Route::get('/course-completion/{token}', [CourseCompletionPortalController::class, 'show'])->name('portal.completion.show');
    Route::post('/course-completion/{token}', [CourseCompletionPortalController::class, 'store'])->name('portal.completion.store');
});

Route::middleware(['auth', 'user-access', 'staff-session'])->group(function () {
    Route::get('/', function (Request $request) {
        if (! $request->user()->canUseRecordsWorkspace()) {
            return redirect()->route('forms.index');
        }
        if (! $request->user()->canAccessBilling()) {
            return redirect()->route('workspace.index');
        }
        if (! $request->user()->is_admin) {
            return redirect()->route('students.index');
        }
        $counts = [];
        foreach (array_keys(BillingTables::MONEY) as $table) {
            $counts[$table] = DB::table($table)->count();
        }

        return view('home', ['counts' => $counts]);
    })->name('home');
    Route::post('/logout', [SessionController::class, 'destroy'])->name('logout');
    Route::get('/records', [RecordsController::class, 'index'])->name('workspace.index');
    Route::get('/records/export', [RecordsController::class, 'export'])->name('workspace.export');
    Route::get('/records/locks', [RecordsController::class, 'tableLocks'])->name('workspace.table-locks');
    Route::get('/records/create', [RecordsController::class, 'create'])->name('workspace.create');
    Route::post('/records', [RecordsController::class, 'store'])->name('workspace.store');
    Route::get('/records/{student}/edit', [RecordsController::class, 'edit'])->name('workspace.edit');
    Route::post('/records/{student}/claim', [RecordsController::class, 'claim'])->name('workspace.claim');
    Route::patch('/records/{student}', [RecordsController::class, 'update'])->middleware('student-lock')->name('workspace.update');
    Route::get('/records/{student}/lock', [LockController::class, 'status'])->name('workspace.status');
    Route::post('/records/{student}/release', [LockController::class, 'release'])->name('workspace.release');
    Route::get('/session/status', [LockController::class, 'sessionStatus'])->name('session.status');
    Route::post('/session/activity', [LockController::class, 'activity'])->name('session.activity');
    Route::get('/students/{student}/editing-lock', [LockController::class, 'status'])->name('locks.status');
    Route::post('/students/{student}/editing-lock/release', [LockController::class, 'release'])->name('locks.release');
    Route::get('/forms', [FormsController::class, 'index'])->name('forms.index');
    Route::get('/forms/learners', [FormsController::class, 'learners'])->name('forms.learners');
    Route::get('/forms/course-completion/{completion}', [CourseCompletionController::class, 'show'])->whereNumber('completion')->name('forms.completion.show');
    Route::get('/forms/course-completion/{completion}/pdf', [CourseCompletionController::class, 'pdf'])->whereNumber('completion')->name('forms.completion.pdf');
    Route::get('/workflows', [WorkflowController::class, 'index'])->name('workflows.index');
    Route::get('/workflows/tasks', [WorkflowController::class, 'tasks'])->name('workflows.tasks');
    Route::get('/workflows/runs/{run}', [WorkflowController::class, 'run'])->whereNumber('run')->name('workflows.runs.show');
    Route::post('/workflows/runs/{run}/pause', [WorkflowController::class, 'pause'])->whereNumber('run')->name('workflows.runs.pause');
    Route::post('/workflows/runs/{run}/resume', [WorkflowController::class, 'resume'])->whereNumber('run')->name('workflows.runs.resume');
    Route::post('/workflows/runs/{run}/send-back', [WorkflowController::class, 'sendBack'])->whereNumber('run')->middleware('admin')->name('workflows.runs.send-back');
    Route::post('/workflows/runs/{run}/cancel', [WorkflowController::class, 'cancel'])->whereNumber('run')->middleware('admin')->name('workflows.runs.cancel');
    Route::post('/workflows/steps/{step}/complete', [WorkflowController::class, 'complete'])->whereNumber('step')->name('workflows.steps.complete');
    Route::post('/workflows/steps/{step}/retry', [WorkflowController::class, 'retry'])->whereNumber('step')->name('workflows.steps.retry');
    Route::post('/workflows/steps/{step}/skip', [WorkflowController::class, 'skip'])->whereNumber('step')->middleware('admin')->name('workflows.steps.skip');
    Route::post('/workflows/steps/{step}/replace-file', [WorkflowController::class, 'replaceFile'])->whereNumber('step')->name('workflows.steps.replace-file');
    Route::get('/workflows/steps/{step}/files/{field}', [WorkflowController::class, 'file'])->whereNumber('step')->name('workflows.steps.file');
    Route::get('/admin/workflow-settings', [WorkflowSettingsController::class, 'edit'])->middleware('admin')->name('workflows.settings.edit');
    Route::put('/admin/workflow-settings', [WorkflowSettingsController::class, 'update'])->middleware('admin')->name('workflows.settings.update');
    Route::get('/workflows/{workflow}', [WorkflowController::class, 'show'])->where('workflow', '[a-z0-9-]+')->name('workflows.show');
    Route::get('/email-templates', [EmailTemplateController::class, 'index'])->name('templates.index');
    Route::get('/email-templates/{key}', [EmailTemplateController::class, 'edit'])->name('templates.edit');
    Route::put('/email-templates/{key}', [EmailTemplateController::class, 'update'])->name('templates.update');
    Route::get('/forms/final-grades/create', [FinalGradesController::class, 'create'])->name('forms.grades.create');
    Route::get('/forms/final-grades/existing', [FinalGradesController::class, 'existing'])->name('forms.grades.existing');
    Route::post('/forms/final-grades', [FinalGradesController::class, 'store'])->name('forms.grades.store');
    Route::get('/forms/final-grades/{sheet}', [FinalGradesController::class, 'edit'])->whereNumber('sheet')->name('forms.grades.edit');
    Route::put('/forms/final-grades/{sheet}', [FinalGradesController::class, 'update'])->whereNumber('sheet')->name('forms.grades.update');
    Route::delete('/forms/final-grades/{sheet}', [FinalGradesController::class, 'destroy'])->whereNumber('sheet')->middleware('admin')->name('forms.grades.destroy');
    Route::get('/forms/final-grades/{sheet}/pdf', [FinalGradesController::class, 'pdf'])->whereNumber('sheet')->name('forms.grades.pdf');
    Route::get('/forms/final-grades/{sheet}/lock', [FinalGradesController::class, 'status'])->whereNumber('sheet')->name('forms.grades.status');
    Route::post('/forms/final-grades/{sheet}/release', [FinalGradesController::class, 'release'])->whereNumber('sheet')->name('forms.grades.release');
    Route::get('/forms/{type}/create', [FormsController::class, 'create'])->where('type', 'graduation-initiation')->name('forms.create');
    Route::post('/forms/{type}', [FormsController::class, 'store'])->where('type', 'graduation-initiation')->name('forms.store');
    Route::get('/forms/{form}', [FormsController::class, 'edit'])->whereNumber('form')->name('forms.edit');
    Route::put('/forms/{form}', [FormsController::class, 'update'])->whereNumber('form')->name('forms.update');
    Route::delete('/forms/{form}', [FormsController::class, 'destroy'])->whereNumber('form')->middleware('admin')->name('forms.destroy');
    Route::get('/forms/{form}/pdf', [FormsController::class, 'pdf'])->whereNumber('form')->name('forms.pdf');
    Route::get('/forms/{form}/lock', [FormsController::class, 'status'])->whereNumber('form')->name('forms.status');
    Route::post('/forms/{form}/release', [FormsController::class, 'release'])->whereNumber('form')->name('forms.release');
    Route::get('/admin/locks', [LockController::class, 'index'])->middleware('admin')->name('locks.index');
    Route::put('/admin/locks/settings', [LockController::class, 'settings'])->middleware('admin')->name('locks.settings');
    Route::post('/admin/locks/release', [LockController::class, 'forceRelease'])->middleware('admin')->name('locks.force-release');
    Route::resource('users', UserController::class)->except('show')->middleware('admin');
    Route::get('/browse', [StudentController::class, 'index'])->name('students.index');
    Route::get('/reports', [ReportController::class, 'index'])->name('reports.index');
    Route::get('/reports/attributable-income', [ReportController::class, 'attributable'])->name('reports.attributable');
    Route::get('/reports/expected-income', [ReportController::class, 'expected'])->name('reports.expected');
    Route::get('/reports/transactions', [ReportController::class, 'transactions'])->name('reports.transactions');
    Route::get('/reports/earned-income', [ReportController::class, 'earned'])->name('reports.earned');
    Route::get('/reports/collections', [ReportController::class, 'collections'])->name('reports.collections');
    Route::get('/reports/receivables', [ReportController::class, 'receivables'])->name('reports.receivables');
    Route::get('/reports/invoices', [ReportController::class, 'invoices'])->name('reports.invoices');
    Route::get('/reports/prepays', [ReportController::class, 'prepays'])->name('reports.prepays');
    Route::get('/create', [StudentController::class, 'create'])->name('students.create');
    Route::post('/students', [StudentController::class, 'store'])->name('students.store');
    Route::get('/detail/{student}', [StudentController::class, 'show'])->name('students.show');
    Route::patch('/students/{student}', [StudentController::class, 'update'])->middleware('student-lock')->name('students.update');
    Route::post('/students/{student}/archive', [StudentController::class, 'archive'])->middleware('student-lock')->name('students.archive');
    Route::post('/balances/recalculate', [StudentController::class, 'recalculate'])->name('balances.recalculate');
    Route::get('/students/{student}/invoices/{invoice}/pdf', [BillingRecordController::class, 'pdf'])->name('invoices.pdf');
    Route::get('/students/{student}/invoices/{invoice}/html', [DocumentController::class, 'invoice'])->name('invoices.html');
    Route::get('/students/{student}/invoices/{invoice}/current-pdf', [DocumentController::class, 'invoice'])->name('invoices.current-pdf');
    Route::get('/students/{student}/statement', [DocumentController::class, 'statement'])->name('statements.html');
    Route::get('/students/{student}/statement/pdf', [DocumentController::class, 'statement'])->name('statements.pdf');
    Route::get('/paymentplans', [PaymentPlanController::class, 'index'])->name('plans.index');
    Route::post('/paymentplans', [PaymentPlanController::class, 'store'])->name('plans.store');
    Route::patch('/paymentplans/{plan}', [PaymentPlanController::class, 'update'])->name('plans.update');
    Route::delete('/paymentplans/{plan}', [PaymentPlanController::class, 'destroy'])->name('plans.destroy');
    Route::get('/students/{student}/schedule', [PaymentPlanController::class, 'show'])->name('schedule.show');
    Route::post('/students/{student}/schedule/generate', [PaymentPlanController::class, 'generate'])->middleware('student-lock')->name('schedule.generate');
    Route::put('/students/{student}/schedule', [PaymentPlanController::class, 'save'])->middleware('student-lock')->name('schedule.save');
    Route::get('/students/{student}/{kind}', [BillingRecordController::class, 'index'])->where('kind', 'transactions|invoices|loas')->name('records.index');
    Route::post('/students/{student}/{kind}', [BillingRecordController::class, 'store'])->where('kind', 'transactions|invoices|loas')->middleware('student-lock')->name('records.store');
    Route::patch('/students/{student}/{kind}/{record}', [BillingRecordController::class, 'update'])->where('kind', 'transactions|invoices|loas')->middleware('student-lock')->name('records.update');
    Route::delete('/students/{student}/{kind}/{record}', [BillingRecordController::class, 'destroy'])->where('kind', 'transactions|invoices|loas')->middleware('student-lock')->name('records.destroy');
});
