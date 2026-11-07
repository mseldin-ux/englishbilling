<?php

namespace Tests\Feature;

use App\Billing\EditLocks;
use App\Billing\RecordVersion;
use App\Models\Invoice;
use App\Models\LeaveOfAbsence;
use App\Models\Student;
use App\Models\StudentTransaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class EditLocksTest extends TestCase
{
    use RefreshDatabase;

    private Student $student;

    private User $alice;

    private User $bob;

    private string $aliceCookie;

    private string $bobCookie;

    protected function setUp(): void
    {
        parent::setUp();
        $this->alice = User::factory()->create(['name' => 'Alice Editor', 'username' => 'alice', 'is_admin' => false]);
        $this->bob = User::factory()->create(['name' => 'Bob Reader', 'username' => 'bob', 'is_admin' => false]);
        $this->aliceCookie = str_repeat('a', 40);
        $this->bobCookie = str_repeat('b', 40);
        $this->student = new Student;
        $this->student->forceFill(['first_name' => 'Synthetic', 'balance' => '0.00'])->save();
        $this->asSession($this->alice, $this->aliceCookie);
    }

    private function asSession(User $user, string $cookie): void
    {
        $this->actingAs($user)->withCredentials()->withCookie(config('session.cookie'), $cookie);
        $this->withHeader('X-Student-Lock', '');
    }

    private function claim(): string
    {
        return $this->get('/detail/'.$this->student->id)->assertOk()->viewData('editLock')['token'];
    }

    private function save(string $token, string $name = 'Saved')
    {
        return $this->withHeader('X-Student-Lock', $token)->patchJson('/students/'.$this->student->id, [
            'first_name' => $name, 'version' => RecordVersion::of($this->student->fresh()),
        ]);
    }

    public function test_automatic_student_wide_lock_names_owner_and_blocks_all_mutation_routes(): void
    {
        $token = $this->claim();
        $this->assertSame(64, strlen($token));
        $this->save($token)->assertOk();
        $this->asSession($this->bob, $this->bobCookie);
        $response = $this->get('/detail/'.$this->student->id)->assertOk()->assertSee('Alice Editor (alice)');
        $this->assertFalse($response->viewData('editLock')['owned']);
        $this->assertSame('', $response->viewData('editLock')['token']);
        $this->save($token, 'Forbidden')->assertStatus(423);
        $this->postJson("/students/{$this->student->id}/archive", ['archived' => true])->assertStatus(423);
        foreach (['transactions' => StudentTransaction::class, 'invoices' => Invoice::class, 'loas' => LeaveOfAbsence::class] as $kind => $class) {
            $record = new $class;
            $record->forceFill(['student_id' => $this->student->id])->save();
            $url = "/students/{$this->student->id}/{$kind}";
            $this->get($url)->assertOk()->assertSee('Alice Editor');
            $this->postJson($url, [])->assertStatus(423);
            $this->patchJson($url.'/'.$record->id, [])->assertStatus(423);
            $this->deleteJson($url.'/'.$record->id, [])->assertStatus(423);
            $this->assertDatabaseHas($record->getTable(), ['id' => $record->id]);
        }
        $this->postJson("/students/{$this->student->id}/schedule/generate", [])->assertStatus(423);
        $this->putJson("/students/{$this->student->id}/schedule", [])->assertStatus(423);
        $this->postJson('/balances/recalculate', ['confirm' => true])->assertStatus(423);
        $this->assertSame('Saved', $this->student->fresh()->first_name);
        $this->assertSame('0.00', $this->student->fresh()->balance);
    }

    public function test_no_lock_no_token_and_wrong_page_tokens_never_allow_writes(): void
    {
        $this->save('')->assertStatus(423);
        $token = $this->claim();
        $this->save('')->assertStatus(423);
        $this->save(str_repeat('c', 64))->assertStatus(423);
        $replacement = $this->claim();
        $this->assertNotSame($token, $replacement);
        $this->save($token)->assertStatus(423);
        $this->save($replacement)->assertOk();
        $this->withHeader('X-Student-Lock', $token)->getJson("/students/{$this->student->id}/editing-lock")
            ->assertOk()->assertJsonPath('owned', false);
    }

    public function test_navigation_release_is_token_scoped_and_an_old_beacon_cannot_release_new_lock(): void
    {
        $old = $this->claim();
        $new = $this->claim();
        $url = "/students/{$this->student->id}/editing-lock/release";
        $this->postJson($url, ['token' => $old])->assertOk();
        $this->save($new)->assertOk();
        $this->asSession($this->bob, $this->bobCookie);
        $this->postJson($url, ['token' => $new])->assertOk();
        $this->assertDatabaseCount('student_edit_locks', 1);
        $this->asSession($this->alice, $this->aliceCookie);
        $this->postJson($url, ['token' => $new])->assertOk();
        $this->assertDatabaseCount('student_edit_locks', 0);
        $this->save($new)->assertStatus(423);
        $this->asSession($this->bob, $this->bobCookie);
        $this->assertNotSame('', $this->claim());
    }

    public function test_idle_polling_does_not_extend_session_and_exact_deadline_logs_out_and_releases(): void
    {
        $this->freezeTime();
        $token = $this->claim();
        $last = DB::table('staff_sessions')->where('id', hash('sha256', $this->aliceCookie))->value('last_activity');
        $this->travel(59)->minutes();
        $this->withHeader('X-Student-Lock', $token)->getJson("/students/{$this->student->id}/editing-lock")->assertOk();
        $this->getJson('/session/status')->assertOk();
        $this->assertSame($last, DB::table('staff_sessions')->where('id', hash('sha256', $this->aliceCookie))->value('last_activity'));
        $this->travel(1)->minutes();
        $this->getJson('/session/status')->assertUnauthorized()->assertJsonPath('message', 'Your session expired due to inactivity. Your editing locks have been released. Log in again.');
        $this->assertDatabaseCount('student_edit_locks', 0);
        $this->assertGuest();
        $this->asSession($this->alice, $this->aliceCookie);
        $this->save($token)->assertUnauthorized();
    }

    public function test_real_activity_extends_session_and_timeout_applies_across_open_tabs(): void
    {
        $this->freezeTime();
        $token = $this->claim();
        $this->travel(50)->minutes();
        $this->postJson('/session/activity', [])->assertOk();
        $this->assertSame(now()->timestamp, DB::table('student_edit_locks')->where('student_id', $this->student->id)->value('last_activity'));
        $this->travel(50)->minutes();
        $this->artisan('billing:clear-expired-locks')->assertSuccessful();
        $this->assertDatabaseCount('student_edit_locks', 1);
        $this->withHeader('X-Student-Lock', $token)->getJson("/students/{$this->student->id}/editing-lock")
            ->assertOk()->assertJsonPath('owned', true);
        $this->travel(10)->minutes();
        $this->get('/browse')->assertRedirect('/login');
        $this->assertDatabaseCount('student_edit_locks', 0);
    }

    public function test_expired_lock_is_available_to_another_session_even_without_cleanup_running(): void
    {
        $this->freezeTime();
        $token = $this->claim();
        $this->travel(60)->minutes();
        $this->asSession($this->bob, $this->bobCookie);
        $this->assertNotSame('', $this->claim());
        $this->asSession($this->alice, $this->aliceCookie);
        $this->save($token)->assertUnauthorized();
        $this->assertDatabaseCount('student_edit_locks', 1);
    }

    public function test_logout_releases_only_this_session_and_deleted_user_releases_all_their_locks(): void
    {
        $this->claim();
        $other = new Student;
        $other->save();
        $this->get('/detail/'.$other->id)->assertOk();
        $this->assertDatabaseCount('student_edit_locks', 2);
        $this->post('/logout')->assertRedirect('/login');
        $this->assertDatabaseCount('student_edit_locks', 0);
        $this->asSession($this->alice, str_repeat('d', 40));
        $this->claim();
        $admin = User::factory()->create(['is_admin' => true]);
        $this->asSession($admin, str_repeat('e', 40));
        $this->delete('/users/'.$this->alice->id)->assertRedirect('/users');
        $this->assertDatabaseCount('student_edit_locks', 0);
    }

    public function test_admin_settings_force_release_and_stale_admin_forms_do_not_release_replacement(): void
    {
        $token = $this->claim();
        $admin = User::factory()->create(['is_admin' => true]);
        $this->asSession($admin, str_repeat('e', 40));
        $this->get('/admin/locks')->assertOk()->assertSee('Alice Editor')->assertSee('60');
        $this->put('/admin/locks/settings', ['idle_minutes' => 90])->assertRedirect();
        $this->assertSame(90, EditLocks::minutes());
        foreach ([0, 1441, 'bad'] as $value) {
            $this->putJson('/admin/locks/settings', ['idle_minutes' => $value])->assertUnprocessable();
        }
        $this->post('/admin/locks/release', ['student_id' => $this->student->id, 'token' => $token, 'confirm' => true])->assertRedirect();
        $this->asSession($this->alice, $this->aliceCookie);
        $this->save($token)->assertStatus(423);
        $new = $this->claim();
        $this->asSession($admin, str_repeat('e', 40));
        $this->post('/admin/locks/release', ['student_id' => $this->student->id, 'token' => $token, 'confirm' => true])->assertRedirect();
        $this->assertDatabaseCount('student_edit_locks', 1);
        $this->postJson('/admin/locks/release', [])->assertUnprocessable();
        $this->post('/admin/locks/release', ['confirm' => true])->assertRedirect();
        $this->asSession($this->alice, $this->aliceCookie);
        $this->save($new)->assertStatus(423);
        $this->get('/admin/locks')->assertForbidden();
        $this->putJson('/admin/locks/settings', ['idle_minutes' => 1])->assertForbidden();
        $this->postJson('/admin/locks/release', ['confirm' => true])->assertForbidden();
    }

    public function test_cleanup_and_shortened_admin_timeout_release_abandoned_locks(): void
    {
        $this->freezeTime();
        $this->claim();
        $this->travel(60)->minutes();
        $this->artisan('billing:clear-expired-locks')->assertSuccessful();
        $this->assertDatabaseCount('student_edit_locks', 0);
        $this->assertDatabaseHas('staff_sessions', ['id' => hash('sha256', $this->aliceCookie)]);
        $this->asSession($this->bob, $this->bobCookie);
        $this->claim();
        $this->travel(2)->minutes();
        $admin = User::factory()->create(['is_admin' => true]);
        $this->asSession($admin, str_repeat('e', 40));
        $this->put('/admin/locks/settings', ['idle_minutes' => 1])->assertRedirect();
        $this->assertDatabaseCount('student_edit_locks', 0);
        $this->asSession($this->bob, $this->bobCookie);
        $this->getJson('/session/status')->assertUnauthorized();
    }

    public function test_browse_archive_is_atomic_without_a_lock_but_cannot_bypass_an_editor(): void
    {
        $url = "/students/{$this->student->id}/archive";
        $this->post($url, ['archived' => true, 'version' => RecordVersion::of($this->student->fresh())])->assertRedirect();
        $this->assertTrue($this->student->fresh()->archived);
        $this->claim();
        $this->asSession($this->bob, $this->bobCookie);
        $this->post($url, ['archived' => false, 'version' => RecordVersion::of($this->student->fresh())])->assertStatus(423);
        $this->assertTrue($this->student->fresh()->archived);
    }
}
