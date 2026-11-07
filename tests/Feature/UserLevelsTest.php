<?php

namespace Tests\Feature;

use App\Billing\RecordVersion;
use App\Models\Invoice;
use App\Models\PaymentPlan;
use App\Models\Student;
use App\Models\StudentTransaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class UserLevelsTest extends TestCase
{
    use RefreshDatabase;

    public function test_no_reports_level_blocks_every_report_including_csv_and_hides_links(): void
    {
        $user = User::factory()->create(['access_level' => 'billing_no_reports']);
        $this->actingAs($user);
        $this->get('/')->assertRedirect('/browse');
        $this->get('/browse')->assertOk()->assertDontSee('href="'.route('reports.index').'"', false);
        foreach (Route::getRoutes() as $route) {
            if (str_starts_with($route->getName() ?? '', 'reports.')) {
                $this->get('/'.$route->uri())->assertForbidden();
                $this->get('/'.$route->uri().'?format=csv')->assertForbidden();
            }
        }
        $this->get('/users')->assertForbidden();
        $this->get('/admin/locks')->assertForbidden();
    }

    public function test_no_reports_staff_can_use_billing_and_existing_student_locks(): void
    {
        $this->actingAs(User::factory()->create(['access_level' => 'billing_no_reports']));
        $student = new Student;
        $student->forceFill(['first_name' => 'Synthetic', 'balance' => '0.00'])->save();
        $this->get('/create')->assertOk();
        $this->get('/paymentplans')->assertForbidden();
        $this->get("/students/{$student->id}/schedule")->assertOk();
        $this->obtainEditingLock($student);
        $this->postJson("/students/{$student->id}/transactions", [
            'transactiondate' => '2026-11-04', 'processeddate' => '2026-11-04',
            'transactiontype' => 'Fee', 'transactionamount' => '12.34',
        ])->assertOk();
        $this->assertSame('12.34', $student->fresh()->balance);
        $transaction = StudentTransaction::firstOrFail();
        $url = "/students/{$student->id}/transactions/{$transaction->id}";
        $this->get("/students/{$student->id}/transactions")->assertOk();
        $this->patchJson($url, [
            'version' => RecordVersion::of($transaction), 'transactionamount' => '15.00',
        ])->assertOk();
        $this->assertSame('15.00', $student->fresh()->balance);
        $this->deleteJson($url, ['version' => RecordVersion::of($transaction->fresh())])->assertOk();
        $this->assertSame('0.00', $student->fresh()->balance);
        $this->withHeader('X-Student-Lock', '')->postJson("/students/{$student->id}/transactions", [])
            ->assertStatus(423);
    }

    public function test_records_level_is_denied_all_billing_routes_and_all_write_methods(): void
    {
        $user = User::factory()->create(['access_level' => 'records']);
        $student = new Student;
        $student->save();
        $invoice = new Invoice;
        $invoice->forceFill(['student_id' => $student->id])->save();
        $plan = new PaymentPlan;
        $plan->save();
        $this->actingAs($user);
        $this->get('/')->assertRedirect('/records');
        $this->get('/records')->assertOk()->assertDontSee('Billing foundation')
            ->assertDontSee('find-dialog')->assertDontSee('billing.js');
        foreach (Route::getRoutes() as $route) {
            if (! in_array('user-access', $route->gatherMiddleware(), true)
                || str_starts_with($route->getName() ?? '', 'workspace.')
                || str_starts_with($route->getName() ?? '', 'forms.')
                || str_starts_with($route->getName() ?? '', 'templates.')
                || (str_starts_with($route->getName() ?? '', 'workflows.') && ! in_array('admin', $route->gatherMiddleware(), true))
                || in_array($route->getName(), ['home', 'logout', 'session.status', 'session.activity'], true)) {
                continue;
            }
            $uri = preg_replace_callback('/\{([^}]+)\}/', fn ($match) => $match[1] === 'kind' ? 'transactions' : '1', $route->uri());
            foreach (array_diff($route->methods(), ['HEAD']) as $method) {
                $this->call($method, '/'.$uri)->assertForbidden();
            }
        }
        $this->assertDatabaseCount('student_edit_locks', 0);
        $this->getJson('/session/status')->assertOk();
        $this->post('/logout')->assertRedirect('/login');
    }

    public function test_admin_can_assign_both_levels_and_invalid_levels_are_rejected(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $this->actingAs($admin);
        $this->get('/users/create')->assertOk()->assertSee('Billing Staff (No Reports)')->assertSee('records');
        foreach (['records', 'billing_no_reports'] as $level) {
            $this->post('/users', [
                'username' => $level, 'name' => 'Synthetic', 'level' => $level,
                'password' => 'synthetic-password', 'password_confirmation' => 'synthetic-password',
            ])->assertRedirect('/users');
            $user = User::where('username', $level)->firstOrFail();
            $this->assertSame($level, $user->access_level);
            $this->assertFalse($user->is_admin);
            $this->put("/users/{$user->id}", [
                'username' => $user->username, 'name' => $user->name, 'level' => 'staff',
            ])->assertRedirect('/users');
            $this->assertSame('staff', $user->fresh()->access_level);
        }
        $this->put("/users/{$admin->id}", [
            'username' => $admin->username, 'name' => $admin->name, 'level' => 'records',
        ])->assertSessionHasErrors('is_admin');
        $this->putJson("/users/{$admin->id}", [
            'username' => $admin->username, 'name' => $admin->name, 'level' => 'unknown',
        ])->assertUnprocessable();
    }

    public function test_existing_staff_and_admin_access_is_preserved_and_admin_overrides_level(): void
    {
        $this->actingAs(User::factory()->create());
        $this->get('/reports')->assertOk();
        $this->actingAs(User::factory()->create(['is_admin' => true, 'access_level' => 'records']));
        $this->get('/reports')->assertOk();
        $this->get('/users')->assertOk()->assertSee('Admin');
        $this->get('/admin/locks')->assertOk();
    }

    public function test_default_views_and_navigation_follow_each_user_level(): void
    {
        foreach (['staff', 'billing_no_reports', 'records'] as $level) {
            $this->actingAs(User::factory()->create(['access_level' => $level]));
            $this->get('/')->assertRedirect($level === 'records' ? '/records' : '/browse');
            $response = $this->get($level === 'records' ? '/records' : '/browse')->assertOk();
            if ($level === 'records') {
                $response->assertSee('Student Records')->assertDontSee('Brief Records View');
            } else {
                $response->assertSee('Brief Records View');
                $html = $response->getContent();
                $this->assertLessThan(strpos($html, '>Brief Records View</a>'), strpos($html, '>Create an AL</a>'));
            }
            if ($level === 'billing_no_reports') {
                $response->assertDontSee('href="'.route('plans.index').'"', false);
                $plan = new PaymentPlan;
                $plan->save();
                $this->post('/paymentplans', [])->assertForbidden();
                $this->patch('/paymentplans/'.$plan->id, [])->assertForbidden();
                $this->delete('/paymentplans/'.$plan->id)->assertForbidden();
            }
        }
        $this->actingAs(User::factory()->create(['is_admin' => true, 'access_level' => 'billing_no_reports']));
        $this->get('/')->assertOk()->assertSee('Billing foundation')->assertSee('Brief Records View');
        $this->get('/paymentplans')->assertOk();
        $this->actingAs(User::factory()->create(['access_level' => 'staff']));
        $this->get('/paymentplans')->assertOk();
    }

    public function test_changing_level_releases_old_leases_and_denies_an_already_authenticated_records_user(): void
    {
        $staff = User::factory()->create();
        $this->actingAs($staff);
        $student = new Student;
        $student->save();
        $this->obtainEditingLock($student);
        $admin = User::factory()->create(['is_admin' => true]);
        $this->actingAs($admin)->withCookie(config('session.cookie'), str_repeat('z', 40))->put("/users/{$staff->id}", [
            'username' => $staff->username, 'name' => $staff->name, 'level' => 'records',
        ])->assertRedirect('/users');
        $this->assertDatabaseCount('student_edit_locks', 0);
        $this->actingAs($staff->fresh())->get('/detail/'.$student->id)->assertForbidden();
        $this->postJson("/students/{$student->id}/transactions", [])->assertForbidden();
    }
}
