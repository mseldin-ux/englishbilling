<?php

namespace Tests\Feature;

use App\Models\FormData;
use App\Models\Invoice;
use App\Models\PaymentPlan;
use App\Models\Program;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class FormsTest extends TestCase
{
    use RefreshDatabase;

    private Student $student;

    private Program $cert;

    private Program $pd;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cert = $this->program('EL I Full', 'cert');
        $this->pd = $this->program('Workshop', 'pd');
        $this->student = new Student;
        $this->student->forceFill(['first_name' => 'Ada', 'last_name' => 'Learner', 'cohort' => 'EL-2026',
            'program_id' => $this->cert->id, 'balance' => '0.00'])->save();
    }

    private function program(string $description, string $type): Program
    {
        $program = new Program;
        $program->forceFill(['program' => $description, 'programdesc' => $description, 'type' => $type, 'servicedays' => 100])->save();

        return $program;
    }

    private function complete(array $overrides = []): array
    {
        return [
            'student_id' => $this->student->id, 'program_id' => $this->cert->id,
            'program_complete' => '1', 'coursework_complete' => '1', 'residency_complete' => '1',
            'residency_location' => 'Hartford, CT', 'residency_date' => '2026-06-15',
            'practicum_passed_date' => '2026-08-01', 'graduation_date' => '2026-09-10',
            'director_signature' => 'Jane Director', ...$overrides,
        ];
    }

    private function asSession(User $user, string $cookie): void
    {
        $this->flushHeaders();
        $this->actingAs($user)->withCredentials()->withCookie(config('session.cookie'), str_repeat($cookie, 40));
        $this->withHeader('X-Student-Lock', '');
    }

    private function lockForm(FormData $form): string
    {
        $token = $this->get('/forms/'.$form->id)->assertOk()->viewData('editLock')['token'];
        $this->assertNotSame('', $token);
        $this->withCookie(config('session.cookie'), app('session')->getId());
        $this->withCredentials();
        $this->withHeader('X-Student-Lock', $token);

        return $token;
    }

    private function draft(): FormData
    {
        $this->post('/forms/graduation-initiation', ['student_id' => $this->student->id, 'action' => 'draft'])
            ->assertRedirect();

        return FormData::latest('id')->firstOrFail();
    }

    public function test_forms_level_can_only_use_forms(): void
    {
        $invoice = new Invoice;
        $invoice->forceFill(['student_id' => $this->student->id])->save();
        (new PaymentPlan)->save();
        $this->actingAs(User::factory()->create(['access_level' => 'forms']));
        $this->get('/')->assertRedirect('/forms');
        $this->get('/forms')->assertOk()->assertSee('New Adult Learner Graduation Initiation')
            ->assertDontSee('Student Records')->assertDontSee('href="'.route('reports.index').'"', false);
        foreach (Route::getRoutes() as $route) {
            $name = $route->getName() ?? '';
            if (! in_array('user-access', $route->gatherMiddleware(), true) || str_starts_with($name, 'forms.')
                || in_array($name, ['home', 'logout', 'session.status', 'session.activity'], true)) {
                continue;
            }
            $uri = preg_replace_callback('/\{([^}]+)\}/', fn ($match) => $match[1] === 'kind' ? 'transactions' : '1', $route->uri());
            foreach (array_diff($route->methods(), ['HEAD']) as $method) {
                $this->call($method, '/'.$uri)->assertForbidden();
            }
        }
        $this->getJson('/forms/learners?q=Ada')->assertOk()
            ->assertExactJson(['learners' => [['id' => $this->student->id, 'name' => 'Ada Learner', 'cohort' => 'EL-2026', 'program_id' => $this->cert->id]]]);
        $form = $this->draft();
        $this->lockForm($form);
        $this->put('/forms/'.$form->id, [...$this->complete(), 'revision' => 1, 'action' => 'submit'])->assertRedirect();
        $this->assertSame('submitted', $form->fresh()->status);
        $this->delete('/forms/'.$form->id, ['confirm' => '1'])->assertForbidden();
    }

    public function test_every_other_level_can_use_forms_and_sees_the_nav_link(): void
    {
        foreach (['records', 'staff', 'billing_no_reports'] as $level) {
            $this->actingAs(User::factory()->create(['access_level' => $level]));
            $this->get('/forms')->assertOk();
            $this->get('/forms/graduation-initiation/create?student='.$this->student->id)->assertOk()
                ->assertSee('Ada Learner')->assertSee('Certificates will be ordered on the 10th of each month.');
            $this->assertSame($this->cert->id, $this->get('/forms/graduation-initiation/create?student='.$this->student->id)
                ->viewData('values')['program_id']);
            $this->get($level === 'records' ? '/records' : '/browse')->assertSee('href="'.route('forms.index').'"', false);
        }
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $this->get('/forms')->assertOk();
    }

    public function test_draft_needs_only_a_learner_and_submit_enforces_every_rule(): void
    {
        $this->actingAs(User::factory()->create());
        $this->post('/forms/graduation-initiation', ['action' => 'draft'])->assertSessionHasErrors('student_id');
        $form = $this->draft();
        $this->assertSame('draft', $form->status);
        $this->assertNull($form->submitted_at);
        $this->assertSame('Ada Learner', $form->data['student_name']);
        $this->assertNull($form->data['signed_by']);

        $cases = [
            'program_complete' => ['program_complete' => null],
            'program_id' => ['program_id' => $this->pd->id],
            'residency_location' => ['residency_location' => ''],
            'residency_date' => ['residency_date' => '2026-09-10'],
            'practicum_passed_date' => ['practicum_passed_date' => '2026-09-11'],
            'graduation_date' => ['graduation_date' => 'not-a-date'],
            'director_signature' => ['director_signature' => '  '],
        ];
        foreach ($cases as $field => $override) {
            $input = array_filter($this->complete($override), fn ($value) => $value !== null);
            $this->post('/forms/graduation-initiation', [...$input, 'action' => 'submit'])->assertSessionHasErrors($field);
        }
        $this->assertSame(1, FormData::count());
        $this->post('/forms/graduation-initiation', [...$this->complete(), 'action' => 'submit'])->assertRedirect();
        $submitted = FormData::latest('id')->firstOrFail();
        $this->assertSame('submitted', $submitted->status);
        $this->assertTrue($submitted->data['coursework_complete']);
        $this->assertSame('2026-09-10', $submitted->data['graduation_date']);
        $this->assertSame('EL I Full', $submitted->data['program_name']);
        $this->assertSame(auth()->user()->username, $submitted->data['signed_by']['username']);
        $this->assertNotNull($submitted->data['signed_at']);
        $this->assertSame(1, DB::table('formsdata_history')->where('formsdata_id', $submitted->id)->where('action', 'submitted')->count());
    }

    public function test_edits_are_versioned_locked_and_recorded_in_history(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $form = $this->draft();
        $token = $this->lockForm($form);
        $this->assertDatabaseHas('form_edit_locks', ['form_kind' => 'formsdata', 'form_id' => $form->id, 'token' => $token]);
        $this->put('/forms/'.$form->id, [...$this->complete(), 'revision' => 1, 'action' => 'draft'])->assertRedirect();
        $this->assertSame(2, $form->fresh()->revision);
        $this->put('/forms/'.$form->id, [...$this->complete(), 'revision' => 2, 'action' => 'draft'])
            ->assertSessionHas('status', 'No changes to save.');
        $this->put('/forms/'.$form->id, [...$this->complete(['residency_location' => 'Elsewhere']), 'revision' => 1, 'action' => 'draft'])
            ->assertSessionHasErrors('revision');
        $this->put('/forms/'.$form->id, [...$this->complete(), 'revision' => 2, 'action' => 'submit'])->assertRedirect();
        $form->refresh();
        $this->assertSame('submitted', $form->status);
        $signedAt = $form->data['signed_at'];

        // Submitted forms stay editable but must remain complete; signature metadata survives unrelated edits.
        $this->put('/forms/'.$form->id, [...$this->complete(['director_signature' => '']), 'revision' => 3, 'action' => 'draft'])
            ->assertSessionHasErrors('director_signature');
        $this->put('/forms/'.$form->id, [...$this->complete(['residency_location' => 'Online']), 'revision' => 3, 'action' => 'draft'])->assertRedirect();
        $form->refresh();
        $this->assertSame('submitted', $form->status);
        $this->assertSame($signedAt, $form->data['signed_at']);
        $this->assertSame(['created', 'updated', 'submitted', 'updated'],
            DB::table('formsdata_history')->where('formsdata_id', $form->id)->orderBy('id')->pluck('action')->all());
        $history = $this->get('/forms/'.$form->id)->assertOk()->viewData('history');
        $this->assertContains('Residency location', $history->firstWhere('revision', 4)->changed);

        // A second session sees the form read only and cannot save.
        $this->asSession(User::factory()->create(), 'b');
        $response = $this->get('/forms/'.$form->id)->assertOk();
        $this->assertFalse($response->viewData('editLock')['owned']);
        $this->put('/forms/'.$form->id, [...$this->complete(), 'revision' => 4, 'action' => 'submit'], ['X-Student-Lock' => 'wrong'])
            ->assertStatus(423);
    }

    public function test_admin_deletes_with_lock_and_history_is_retained_and_locks_are_releasable(): void
    {
        $this->actingAs(User::factory()->create());
        $form = $this->draft();
        $this->lockForm($form);
        $this->assertDatabaseCount('form_edit_locks', 1);

        $this->asSession(User::factory()->create(['is_admin' => true]), 'c');
        $this->get('/admin/locks')->assertOk()->assertSee('Ada Learner');
        $lock = DB::table('form_edit_locks')->first();
        $this->post('/admin/locks/release', ['confirm' => '1', 'form_kind' => 'formsdata', 'form_id' => $form->id, 'token' => $lock->token])->assertRedirect();
        $this->assertDatabaseCount('form_edit_locks', 0);

        $this->lockForm($form);
        $this->delete('/forms/'.$form->id, [])->assertSessionHasErrors('confirm');
        $this->delete('/forms/'.$form->id, ['confirm' => '1'])->assertRedirect('/forms');
        $this->assertDatabaseCount('formsdata', 0);
        $this->assertSame(['created', 'deleted'], DB::table('formsdata_history')->orderBy('id')->pluck('action')->all());
    }

    public function test_role_change_releases_form_locks(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $form = $this->draft();
        $this->lockForm($form);
        $this->asSession(User::factory()->create(['is_admin' => true]), 'c');
        $this->put('/users/'.$user->id, ['username' => $user->username, 'name' => $user->name, 'level' => 'forms'])->assertRedirect();
        $this->assertDatabaseCount('form_edit_locks', 0);
    }

    public function test_legacy_non_certificate_program_is_kept_and_pdf_and_search_work(): void
    {
        $this->actingAs(User::factory()->create());
        $this->post('/forms/graduation-initiation', [...$this->complete(), 'action' => 'submit'])->assertRedirect();
        $form = FormData::firstOrFail();
        $this->cert->forceFill(['type' => 'pd'])->save();
        $this->lockForm($form);
        $this->put('/forms/'.$form->id, [...$this->complete(['residency_location' => 'Changed']), 'revision' => 1, 'action' => 'submit'])
            ->assertSessionHasNoErrors();
        $this->put('/forms/'.$form->id, [...$this->complete(['program_id' => $this->pd->id]), 'revision' => 2, 'action' => 'submit'])
            ->assertSessionHasErrors('program_id');

        $pdf = $this->get('/forms/'.$form->id.'/pdf')->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF-', $pdf->getContent());

        $this->get('/forms?q=Ada')->assertOk()->assertSee('Jane Director');
        $this->get('/forms?q=Director')->assertOk()->assertSee('Ada Learner');
        $this->get('/forms?q=Nobody')->assertOk()->assertSee('No forms match.');
        $this->get('/forms?status=draft')->assertOk()->assertSee('No forms match.');
        $this->get('/forms?q='.$this->student->id)->assertOk()->assertSee('Jane Director');
    }
}
