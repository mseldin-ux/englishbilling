<?php

namespace Tests\Feature;

use App\Models\FinalGrade;
use App\Models\GradedItem;
use App\Models\Program;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class FinalGradesTest extends TestCase
{
    use RefreshDatabase;

    private Student $student;

    private Program $full;

    private Program $assoc;

    private Program $empty;

    /** @var array<int, GradedItem> */
    private array $items = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->full = $this->program('EL I Full');
        $this->assoc = $this->program('EL I Assoc');
        $this->empty = $this->program('Workshop');
        $this->student = new Student;
        $this->student->forceFill(['first_name' => 'Ada', 'last_name' => 'Learner', 'cohort' => 'EL-2026',
            'program_id' => $this->full->id, 'balance' => '0.00'])->save();
        // Inserted out of order to prove itemorder drives display order.
        foreach ([[3, 'Practicum', 120], [1, 'Philosophy', null], [2, 'Language', 30]] as [$order, $name, $hours]) {
            $this->items[$order] = GradedItem::create(['program_id' => $this->full->id, 'itemorder' => $order, 'gradeitem' => $name, 'clockhours' => $hours]);
        }
        GradedItem::create(['program_id' => $this->assoc->id, 'itemorder' => 1, 'gradeitem' => 'Assoc Philosophy']);
    }

    private function program(string $description): Program
    {
        $program = new Program;
        $program->forceFill(['program' => $description, 'programdesc' => $description, 'type' => 'cert', 'servicedays' => 100])->save();

        return $program;
    }

    private function grades(array $overrides = []): array
    {
        return array_replace([$this->items[1]->id => 'A', $this->items[2]->id => '93', $this->items[3]->id => 'Pass'], $overrides);
    }

    private function complete(array $overrides = []): array
    {
        return ['studentid' => $this->student->id, 'program_id' => $this->full->id, 'grades' => $this->grades(),
            'gradenotes' => 'Strong practicum.', 'gradername' => 'Jane Director', 'gradedate' => '2026-09-10', ...$overrides];
    }

    private function asSession(User $user, string $cookie): void
    {
        $this->flushHeaders();
        $this->actingAs($user)->withCredentials()->withCookie(config('session.cookie'), str_repeat($cookie, 40));
        $this->withHeader('X-Student-Lock', '');
    }

    private function lockSheet(FinalGrade $sheet): string
    {
        $token = $this->get('/forms/final-grades/'.$sheet->id)->assertOk()->viewData('editLock')['token'];
        $this->assertNotSame('', $token);
        $this->withCookie(config('session.cookie'), app('session')->getId());
        $this->withCredentials();
        $this->withHeader('X-Student-Lock', $token);

        return $token;
    }

    private function draft(): FinalGrade
    {
        $this->post('/forms/final-grades', ['studentid' => $this->student->id, 'program_id' => $this->full->id, 'action' => 'draft'])
            ->assertRedirect();

        return FinalGrade::latest('id')->firstOrFail();
    }

    public function test_every_level_can_open_the_form_and_items_follow_item_order(): void
    {
        foreach (['forms', 'records', 'staff', 'billing_no_reports'] as $level) {
            $this->actingAs(User::factory()->create(['access_level' => $level]));
            $this->get('/forms')->assertOk()->assertSee('New Final Grades');
            $response = $this->get('/forms/final-grades/create?student='.$this->student->id)->assertOk()->assertSee('Ada Learner');
            $this->assertSame($this->full->id, $response->viewData('values')['program_id']);
            $this->assertSame(date('Y-m-d'), $response->viewData('values')['gradedate']);
        }
        $response = $this->get('/forms/final-grades/create')->assertOk()
            ->assertSeeInOrder(['Philosophy', 'Language', '30', 'Practicum', '120'])->assertSee('Clock hours');
        $this->assertEqualsCanonicalizing([$this->full->id, $this->assoc->id], $response->viewData('programs')->pluck('id')->all());
        $this->assertSame([$this->items[1]->id, $this->items[2]->id, $this->items[3]->id],
            $response->viewData('items')->get($this->full->id)->pluck('id')->all());
    }

    public function test_draft_is_lenient_and_submit_requires_every_grade_signature_and_date(): void
    {
        $this->actingAs(User::factory()->create(['access_level' => 'forms']));
        $this->post('/forms/final-grades', ['action' => 'draft'])->assertSessionHasErrors(['studentid', 'program_id']);
        $this->post('/forms/final-grades', ['studentid' => $this->student->id, 'program_id' => $this->empty->id, 'action' => 'draft'])
            ->assertSessionHasErrors('program_id');
        $this->post('/forms/final-grades', $this->complete(['grades' => $this->grades([$this->items[2]->id => '']),
            'gradername' => '', 'gradedate' => '', 'action' => 'submit']))
            ->assertSessionHasErrors(['grades.'.$this->items[2]->id, 'gradername', 'gradedate']);
        $this->post('/forms/final-grades', $this->complete(['grades' => $this->grades([$this->items[1]->id => str_repeat('x', 65)]), 'action' => 'draft']))
            ->assertSessionHasErrors('grades.'.$this->items[1]->id);
        $this->assertDatabaseCount('finalgrades', 0);

        $sheet = $this->draft();
        $this->assertSame('draft', $sheet->status);
        $this->assertSame(3, DB::table('finalgradesitems')->where('finalgradeid', $sheet->id)->whereNull('grade')->count());

        $this->post('/forms/final-grades', $this->complete(['action' => 'submit']))->assertSessionHasErrors('program_id');
        $this->assertDatabaseCount('finalgrades', 1);
        $this->post('/forms/final-grades', $this->complete(['program_id' => $this->assoc->id,
            'grades' => [GradedItem::where('program_id', $this->assoc->id)->value('id') => 'P'], 'action' => 'submit']))->assertRedirect();
        $assoc = FinalGrade::where('program_id', $this->assoc->id)->firstOrFail();
        $this->assertSame('submitted', $assoc->status);
        $this->assertNotNull($assoc->signed_at);
        $this->assertSame('Jane Director', $assoc->gradername);
    }

    public function test_existing_sheets_are_reported_for_a_learner(): void
    {
        $this->actingAs(User::factory()->create(['access_level' => 'forms']));
        $this->getJson('/forms/final-grades/existing?student='.$this->student->id)->assertOk()->assertExactJson(['sheets' => []]);
        $sheet = $this->draft();
        $this->getJson('/forms/final-grades/existing?student='.$this->student->id)->assertOk()
            ->assertJsonPath('sheets.0.id', $sheet->id)->assertJsonPath('sheets.0.program', 'EL I Full')
            ->assertJsonPath('sheets.0.status', 'draft')->assertJsonPath('sheets.0.url', route('forms.grades.edit', $sheet));
        $this->get('/forms/final-grades/create?student='.$this->student->id)->assertOk()
            ->assertSee('already has grade sheets')->assertSee(route('forms.grades.edit', $sheet), false);
        $this->get('/forms?type=final-grades&q=Jane')->assertOk()->assertDontSee('Ada Learner');
        $this->get('/forms?type=final-grades&q=Ada')->assertOk()->assertSee('Ada Learner')->assertSee('Final Grades');
        $this->get('/forms?type=graduation-initiation')->assertOk()->assertDontSee('Ada Learner');
    }

    public function test_edits_are_versioned_locked_and_recorded_in_history(): void
    {
        $this->actingAs(User::factory()->create());
        $sheet = $this->draft();
        $token = $this->lockSheet($sheet);
        $this->assertDatabaseHas('form_edit_locks', ['form_kind' => 'finalgrades', 'form_id' => $sheet->id, 'token' => $token]);

        $this->put('/forms/final-grades/'.$sheet->id, [...$this->complete(['program_id' => $this->assoc->id]), 'revision' => 1, 'action' => 'draft'])
            ->assertRedirect();
        $sheet->refresh();
        $this->assertSame(2, $sheet->revision);
        $this->assertSame($this->full->id, $sheet->program_id);
        $this->assertSame('93', $sheet->items()->where('gradeditemid', $this->items[2]->id)->value('grade'));
        $this->put('/forms/final-grades/'.$sheet->id, [...$this->complete(), 'revision' => 2, 'action' => 'draft'])
            ->assertSessionHas('status', 'No changes to save.');
        $this->put('/forms/final-grades/'.$sheet->id, [...$this->complete(['gradenotes' => 'x']), 'revision' => 1, 'action' => 'draft'])
            ->assertSessionHasErrors('revision');
        $this->put('/forms/final-grades/'.$sheet->id, [...$this->complete(), 'revision' => 2, 'action' => 'submit'])->assertRedirect();
        $sheet->refresh();
        $this->assertSame('submitted', $sheet->status);
        $signedAt = $sheet->signed_at;

        // A submitted sheet stays complete; unrelated edits keep the original signature metadata.
        $this->put('/forms/final-grades/'.$sheet->id, [...$this->complete(['grades' => $this->grades([$this->items[3]->id => ''])]),
            'revision' => 3, 'action' => 'draft'])->assertSessionHasErrors('grades.'.$this->items[3]->id);
        $this->put('/forms/final-grades/'.$sheet->id, [...$this->complete(['grades' => $this->grades([$this->items[3]->id => 'Fail'])]),
            'revision' => 3, 'action' => 'draft'])->assertRedirect();
        $sheet->refresh();
        $this->assertSame('submitted', $sheet->status);
        $this->assertTrue($signedAt->equalTo($sheet->signed_at));
        $this->assertSame(['created', 'updated', 'submitted', 'updated'],
            DB::table('finalgrades_history')->where('finalgradeid', $sheet->id)->orderBy('id')->pluck('action')->all());
        $latest = json_decode(DB::table('finalgrades_history')->where('finalgradeid', $sheet->id)->orderByDesc('id')->value('data'), true);
        $this->assertSame('Fail', $latest['grades'][(string) $this->items[3]->id]['grade']);
        $this->assertSame(120, $latest['grades'][(string) $this->items[3]->id]['clockhours']);
        $this->assertNotEmpty($this->get('/forms/final-grades/'.$sheet->id)->assertOk()->viewData('history')->firstWhere('revision', 4)->changed);

        $pdf = $this->get('/forms/final-grades/'.$sheet->id.'/pdf')->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF-', $pdf->getContent());

        $this->asSession(User::factory()->create(['access_level' => 'forms']), 'b');
        $this->assertFalse($this->get('/forms/final-grades/'.$sheet->id)->assertOk()->viewData('editLock')['owned']);
        $this->put('/forms/final-grades/'.$sheet->id, [...$this->complete(), 'revision' => 4, 'action' => 'submit'], ['X-Student-Lock' => 'wrong'])
            ->assertStatus(423);
    }

    public function test_only_admin_deletes_and_locks_are_listed_releasable_and_cleared(): void
    {
        $user = User::factory()->create(['access_level' => 'forms']);
        $this->actingAs($user);
        $sheet = $this->draft();
        $this->lockSheet($sheet);
        $this->delete('/forms/final-grades/'.$sheet->id, ['confirm' => '1'])->assertForbidden();

        $this->asSession(User::factory()->create(['is_admin' => true]), 'c');
        $this->get('/admin/locks')->assertOk()->assertSee('Final Grades')->assertSee(route('forms.grades.edit', $sheet), false);
        $lock = DB::table('form_edit_locks')->first();
        $this->post('/admin/locks/release', ['confirm' => '1', 'form_kind' => 'finalgrades', 'form_id' => $sheet->id, 'token' => $lock->token])
            ->assertRedirect();
        $this->assertDatabaseCount('form_edit_locks', 0);

        $this->lockSheet($sheet);
        $this->delete('/forms/final-grades/'.$sheet->id, [])->assertSessionHasErrors('confirm');
        $this->delete('/forms/final-grades/'.$sheet->id, ['confirm' => '1'])->assertRedirect('/forms');
        $this->assertDatabaseCount('finalgrades', 0);
        $this->assertDatabaseCount('finalgradesitems', 0);
        $this->assertDatabaseCount('form_edit_locks', 0);
        $this->assertSame(['created', 'deleted'], DB::table('finalgrades_history')->orderBy('id')->pluck('action')->all());
    }

    public function test_role_change_releases_grade_sheet_locks(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $this->lockSheet($this->draft());
        $this->assertDatabaseCount('form_edit_locks', 1);
        $this->asSession(User::factory()->create(['is_admin' => true]), 'c');
        $this->put('/users/'.$user->id, ['username' => $user->username, 'name' => $user->name, 'level' => 'forms'])->assertRedirect();
        $this->assertDatabaseCount('form_edit_locks', 0);
    }
}
