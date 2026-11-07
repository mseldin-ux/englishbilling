<?php

namespace Tests\Feature;

use App\Billing\RecordsFields;
use App\Billing\RecordVersion;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RecordsWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    private Student $student;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['access_level' => 'records']));
        $this->withCredentials()->withCookie(config('session.cookie'), str_repeat('a', 40));
        $this->student = new Student;
        $this->student->forceFill(['first_name' => 'Visible', 'last_name' => 'Learner',
            'phone' => '555', 'balance' => '900.00', 'invoice_notes' => 'PRIVATE_BILLING_NOTE',
            'studentcomments' => 'PRIVATE_COMMENT', 'pif' => true, 'sponsored' => true])->save();
    }

    private function claim(): array
    {
        return $this->postJson("/records/{$this->student->id}/claim", [])->assertOk()->json();
    }

    public function test_guests_cannot_read_copy_create_or_edit_records(): void
    {
        auth()->logout();
        foreach (['/records', '/records/export', '/records/create', "/records/{$this->student->id}/edit"] as $url) {
            $this->get($url)->assertRedirect('/login');
        }
        $this->postJson('/records', [])->assertUnauthorized();
        $this->postJson("/records/{$this->student->id}/claim", [])->assertUnauthorized();
        $this->assertDatabaseCount('student_edit_locks', 0);
    }

    public function test_views_and_json_expose_only_access_fields_and_do_not_lock_browsing(): void
    {
        $this->get('/records')->assertOk()->assertSee('Visible')->assertSee('Edit Row')
            ->assertDontSee('PRIVATE_BILLING_NOTE')->assertDontSee('PRIVATE_COMMENT')->assertDontSee('900.00');
        $this->assertDatabaseCount('student_edit_locks', 0);
        $this->get('/records/create')->assertOk()->assertSee('School Sponsored')->assertDontSee('Paid in full');
        $result = $this->claim();
        $this->assertSame(array_keys(RecordsFields::fields()), array_keys($result['values']));
        $this->assertArrayNotHasKey('balance', $result['values']);
        $this->assertTrue($result['lock']['owned']);
        $this->get("/records/{$this->student->id}/edit")->assertOk()->assertSee('Phone 2 Label')
            ->assertDontSee('PRIVATE_BILLING_NOTE')->assertDontSee('PRIVATE_COMMENT')->assertDontSee('Paid in full');
    }

    public function test_inline_and_form_saves_require_lease_and_reject_other_fields_without_partial_writes(): void
    {
        $result = $this->claim();
        $this->withHeader('X-Student-Lock', $result['lock']['token']);
        $url = "/records/{$this->student->id}";
        $this->patchJson($url, ['version' => $result['version'], 'first_name' => 'Changed',
            'balance' => '0'])->assertUnprocessable()->assertJsonValidationErrors('fields');
        $this->assertSame('Visible', $this->student->fresh()->first_name);
        $response = $this->patchJson($url, ['version' => $result['version'],
            'first_name' => 'Changed', 'access_status_code' => 7])->assertOk();
        $this->assertSame('900.00', $this->student->fresh()->balance);
        $this->assertTrue($this->student->fresh()->pif);
        $this->assertNull($this->student->fresh()->graduated);
        $this->patchJson($url, ['version' => $result['version'], 'phone' => 'Stale'])->assertUnprocessable();
        $this->withHeader('X-Student-Lock', '')->patchJson($url, [
            'version' => $response->json('version'), 'phone' => 'No lock',
        ])->assertStatus(423);
        $this->assertSame('555', $this->student->fresh()->phone);
    }

    public function test_billing_and_records_share_locks_and_polling_does_not_extend_activity(): void
    {
        $this->freezeTime();
        $result = $this->claim();
        $this->travel(59)->minutes();
        $this->getJson('/records/locks?ids[]='.$this->student->id)->assertOk()->assertJsonCount(1, 'locks');
        $this->withHeader('X-Student-Lock', $result['lock']['token'])
            ->getJson("/records/{$this->student->id}/lock")->assertOk()->assertJsonPath('owned', true);
        $this->travel(1)->minutes();
        $this->getJson("/records/{$this->student->id}/lock")->assertUnauthorized();
        $this->assertDatabaseCount('student_edit_locks', 0);
        $this->actingAs(User::factory()->create())->withCookie(config('session.cookie'), str_repeat('b', 40));
        $token = $this->obtainEditingLock($this->student);
        $this->actingAs(User::factory()->create(['access_level' => 'records']))
            ->withCookie(config('session.cookie'), str_repeat('c', 40))->withHeader('X-Student-Lock', '');
        $this->postJson("/records/{$this->student->id}/claim", [])->assertOk()->assertJsonPath('lock.owned', false);
        $this->withHeader('X-Student-Lock', $token)->patchJson("/records/{$this->student->id}", [
            'version' => RecordVersion::of($this->student->fresh()), 'first_name' => 'Forbidden',
        ])->assertStatus(423);
    }

    public function test_creation_whitelist_nullable_fields_and_no_deletion_permission(): void
    {
        $this->post('/records', ['first_name' => 'Created', 'access_status_code' => 3,
            'sponsored' => false, 'phone2label' => null])->assertRedirect();
        $created = Student::where('first_name', 'Created')->firstOrFail();
        $this->assertSame('0.00', $created->balance);
        $this->assertFalse($created->sponsored);
        $this->assertNull($created->pif);
        $this->postJson('/records', ['first_name' => 'Bad', 'pif' => true])->assertUnprocessable();
        $this->delete("/records/{$created->id}")->assertStatus(405);
        $this->assertDatabaseHas('students', ['id' => $created->id]);
    }

    public function test_literal_search_pagination_and_export_selection_never_include_billing_fields(): void
    {
        for ($i = 0; $i < 41; $i++) {
            DB::table('students')->insert(['first_name' => 'Other '.$i, 'balance' => '0']);
        }
        DB::table('students')->insert(['first_name' => 'Literal%_Name']);
        $this->get('/records?field=first_name&value=Literal%25_')->assertOk()
            ->assertSee('Literal%_Name')->assertDontSee('Visible');
        $this->get('/records?field=balance&value=900')->assertSessionHasErrors('field');
        $this->get('/records?field=first_name&match=exact&value=Visible')->assertOk()->assertSee('1 matching rows');
        $response = $this->get('/records/export')->assertOk();
        $text = $response->streamedContent();
        $this->assertStringContainsString('Other 40', $text);
        $this->assertStringNotContainsString('PRIVATE_BILLING_NOTE', $text);
        $this->assertStringNotContainsString('900.00', $text);
        $rows = array_filter(explode("\r\n", $text));
        $this->assertCount(44, $rows);
        $this->assertCount(26, str_getcsv($rows[0], "\t", '"', ''));
        $selected = $this->get('/records/export?ids[]='.$this->student->id)->assertOk()->streamedContent();
        $this->assertStringContainsString('Visible', $selected);
        $this->assertStringNotContainsString('Other', $selected);
        $this->assertDatabaseCount('student_edit_locks', 0);
    }

    public function test_export_escapes_formulas_and_release_invalidates_old_token(): void
    {
        $this->student->first_name = '=SUM(1,2)';
        $this->student->save();
        $text = $this->get('/records/export')->assertOk()->streamedContent();
        $this->assertStringContainsString("'=SUM(1,2)", $text);
        $result = $this->claim();
        $this->postJson("/records/{$this->student->id}/release", ['token' => $result['lock']['token']])->assertOk();
        $this->withHeader('X-Student-Lock', $result['lock']['token'])->patchJson("/records/{$this->student->id}", [
            'version' => $result['version'], 'first_name' => 'Forbidden',
        ])->assertStatus(423);
    }
}
