<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class StaffAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_redirected_and_can_view_login(): void
    {
        $this->get('/')->assertRedirect('/login');
        $this->get('/users')->assertRedirect('/login');
        $this->get('/login')->assertOk()->assertSee('Username');
    }

    public function test_staff_can_log_in_and_out_but_cannot_manage_users(): void
    {
        $user = User::factory()->create(['username' => 'staff', 'password' => 'correct-password']);
        $this->post('/login', ['username' => 'staff', 'password' => 'correct-password'])->assertRedirect('/');
        $this->assertAuthenticatedAs($user);
        $this->get('/')->assertRedirect('/browse');
        $this->get('/users')->assertForbidden();
        $this->post('/users', ['username' => 'intruder'])->assertForbidden();
        $this->put("/users/{$user->id}", ['is_admin' => true])->assertForbidden();
        $this->delete("/users/{$user->id}")->assertForbidden();
        $this->post('/logout')->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_invalid_login_is_reported_and_throttled(): void
    {
        for ($i = 0; $i < 6; $i++) {
            $this->post('/login', ['username' => 'missing', 'password' => 'wrong'])->assertSessionHasErrors('username');
        }
        $this->post('/login', ['username' => 'missing', 'password' => 'wrong'])->assertStatus(429);
        $this->assertGuest();
    }

    public function test_admin_can_create_edit_and_delete_staff_without_email(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $this->actingAs($admin);
        $this->get('/users/create')->assertOk();
        $this->post('/users', ['username' => 'newstaff', 'name' => 'New Staff',
            'password' => 'long-staff-password', 'password_confirmation' => 'long-staff-password'])
            ->assertRedirect('/users');
        $staff = User::where('username', 'newstaff')->firstOrFail();
        $this->assertTrue(Hash::check('long-staff-password', $staff->password));
        $this->assertFalse($staff->is_admin);
        $this->get("/users/{$staff->id}/edit")->assertOk();
        $this->put("/users/{$staff->id}", ['username' => 'renamed', 'name' => 'Renamed',
            'password' => '', 'password_confirmation' => '', 'is_admin' => '1'])->assertRedirect('/users');
        $this->assertTrue($staff->fresh()->is_admin);
        $this->assertTrue(Hash::check('long-staff-password', $staff->fresh()->password));
        $this->delete("/users/{$staff->id}")->assertRedirect('/users');
        $this->assertDatabaseMissing('users', ['id' => $staff->id]);
    }

    public function test_self_deletion_and_last_admin_demotion_are_blocked(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $this->actingAs($admin)->delete("/users/{$admin->id}")->assertSessionHasErrors('user');
        $this->put("/users/{$admin->id}", ['username' => $admin->username, 'name' => $admin->name])
            ->assertSessionHasErrors('is_admin');
        $this->assertTrue($admin->fresh()->is_admin);
    }

    public function test_user_validation_prevents_duplicate_username_and_weak_password(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $this->actingAs($admin)->post('/users', [
            'username' => $admin->username, 'name' => 'Bad', 'password' => 'short',
            'password_confirmation' => 'short',
        ])->assertSessionHasErrors(['username', 'password']);
        $this->assertDatabaseCount('users', 1);
    }

    public function test_interactive_admin_command_creates_hashed_login(): void
    {
        $this->artisan('billing:create-admin')
            ->expectsQuestion('Username', 'owner')
            ->expectsQuestion('Display name', 'Owner')
            ->expectsQuestion('Password (at least 12 characters)', 'long-owner-password')
            ->expectsQuestion('Confirm password', 'long-owner-password')
            ->expectsOutput('Admin created.')->assertSuccessful();
        $user = User::where('username', 'owner')->firstOrFail();
        $this->assertTrue($user->is_admin);
        $this->assertTrue(Hash::check('long-owner-password', $user->password));
    }
}
