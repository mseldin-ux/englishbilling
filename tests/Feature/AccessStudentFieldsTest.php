<?php

namespace Tests\Feature;

use App\Billing\BillingDocument;
use App\Billing\BillingTables;
use App\Billing\RecordsFields;
use App\Billing\RecordVersion;
use App\Billing\StudentFields;
use App\Models\Student;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AccessStudentFieldsTest extends TestCase
{
    use RefreshDatabase;

    private function data(): array
    {
        return [
            'maiden_name' => 'Synthetic Former Name', 'birth_date' => '1990-02-28',
            'address_line_2' => 'Suite 200', 'access_status_code' => 3,
            'access_change_date' => '2026-11-04T13:14:15.123456',
            'self_pay' => true, 'combination_payment' => false, 'self_directed' => null,
            'comp_college' => true, 'associate' => 'Synthetic Associate',
            'phone' => '555-0100', 'phonelabel' => 'Home',
            'phone2' => '555-0101', 'phone2label' => 'Cell',
        ];
    }

    private function learner(): Student
    {
        $this->actingAs(User::factory()->create());
        $student = new Student;
        $student->forceFill(['first_name' => 'Synthetic', 'balance' => '0.00',
            'wd' => true, 'loa' => true, 'sponsored' => true])->save();
        $this->obtainEditingLock($student);

        return $student;
    }

    private function save(Student $student, array $data)
    {
        return $this->patchJson("/students/{$student->id}", [
            'version' => RecordVersion::of($student->fresh()), ...$data,
        ]);
    }

    public function test_new_students_accept_all_new_fields_and_preserve_nullable_values_and_timestamps(): void
    {
        $this->actingAs(User::factory()->create());
        $this->post('/students', ['first_name' => 'Created', ...$this->data()])->assertRedirect();
        $student = Student::where('first_name', 'Created')->firstOrFail();
        $this->assertSame('1990-02-28', $student->birth_date->format('Y-m-d'));
        $this->assertSame('2026-11-04 13:14:15.123456', $student->access_change_date->format('Y-m-d H:i:s.u'));
        $this->assertTrue($student->self_pay);
        $this->assertFalse($student->combination_payment);
        $this->assertNull($student->self_directed);
        $this->assertSame('Cell', $student->phone2label);
        $this->assertSame(3, $student->access_status_code);
        $this->assertNull($student->graduated);
        $this->assertSame('0.00', $student->balance);
        $this->get('/create')->assertOk()->assertSee('Phone 2 Label')->assertSee('audit completed');
    }

    public function test_all_statuses_are_selectable_without_changing_billing_flags_or_sponsorship(): void
    {
        $student = $this->learner();
        $expected = [
            1 => 'applying', 2 => 'in process', 3 => 'enrolled', 4 => 'withdrew',
            5 => 'expelled', 6 => 'leave of absence', 7 => 'graduated', 8 => 'incomplete',
            9 => 'extension', 10 => 'grad-ASSOC', 11 => 'audit completed',
        ];
        $this->assertSame($expected, DB::table('student_access_statuses')->orderBy('id')->pluck('status', 'id')->all());
        foreach ($expected as $code => $label) {
            $this->save($student, ['access_status_code' => $code])->assertOk();
            $fresh = $student->fresh();
            $this->assertSame($code, $fresh->access_status_code);
            $this->assertTrue($fresh->wd);
            $this->assertTrue($fresh->loa);
            $this->assertTrue($fresh->sponsored);
            $this->assertNull($fresh->graduated);
            $this->assertSame('LOA', $fresh->status());
        }
    }

    public function test_originalcohort_is_nullable_history_preserved_during_normal_student_edits(): void
    {
        $student = $this->learner();
        $this->assertNull($student->fresh()->originalcohort);
        $student->forceFill(['cohort' => 'New cohort', 'originalcohort' => str_repeat('x', 255)])->save();
        $this->save($student, ['cohort' => 'Updated cohort'])->assertOk();
        $this->assertSame('Updated cohort', $student->fresh()->cohort);
        $this->assertSame(str_repeat('x', 255), $student->fresh()->originalcohort);
        $this->assertArrayNotHasKey('originalcohort', StudentFields::rules());
        $this->assertArrayNotHasKey('originalcohort', RecordsFields::fields());
    }

    public function test_new_fields_render_once_edit_clear_and_preserve_unsubmitted_values(): void
    {
        $student = $this->learner();
        $this->save($student, $this->data())->assertOk();
        $response = $this->get('/detail/'.$student->id)->assertOk()->assertSee('2026-11-04T13:14:15', false)
            ->assertDontSee('2026-11-04T13:14:15.123456', false);
        foreach (BillingTables::STUDENT_ADDITIONS as $field) {
            $this->assertSame(1, substr_count($response->getContent(), 'name="'.$field.'"'));
        }
        $this->withHeader('X-Student-Lock', $response->viewData('editLock')['token']);
        $this->save($student, ['phone2' => '555-0102'])->assertOk();
        $this->assertSame('Home', $student->fresh()->phonelabel);
        $this->assertSame('Synthetic Former Name', $student->fresh()->maiden_name);
        $this->save($student, [
            'access_change_date' => '2026-11-04T13:14:15',
            'birth_date' => '1990-02-28',
        ])->assertOk();
        $this->assertSame('2026-11-04 13:14:15.123456', $student->fresh()->access_change_date->format('Y-m-d H:i:s.u'));
        $this->save($student, array_fill_keys(BillingTables::STUDENT_ADDITIONS, null))->assertOk();
        foreach (BillingTables::STUDENT_ADDITIONS as $field) {
            $this->assertNull($student->fresh()->getAttribute($field));
        }
        $this->assertSame('555-0100', $student->fresh()->phone);
    }

    public function test_invalid_statuses_dates_boolean_and_field_lengths_fail_without_changes(): void
    {
        $student = $this->learner();
        foreach ([0, 12, -1, 'enrolled', 1.5] as $status) {
            $this->save($student, ['access_status_code' => $status])->assertUnprocessable()
                ->assertJsonValidationErrors('access_status_code');
        }
        $this->save($student, [
            'birth_date' => '2026-02-30', 'access_change_date' => 'not a timestamp',
            'phone2' => str_repeat('1', 33), 'phonelabel' => str_repeat('x', 256),
            'phone2label' => str_repeat('x', 256), 'self_pay' => 'maybe',
        ])->assertUnprocessable()->assertJsonValidationErrors([
            'birth_date', 'access_change_date', 'phone2', 'phonelabel', 'phone2label', 'self_pay',
        ]);
        $this->assertNull($student->fresh()->phone2);
        $this->assertNull($student->fresh()->access_status_code);
    }

    public function test_database_rejects_undefined_status_codes_even_when_imported_directly(): void
    {
        $student = $this->learner();
        $this->expectException(QueryException::class);
        DB::table('students')->where('id', $student->id)->update(['access_status_code' => 12]);
    }

    public function test_new_fields_use_existing_student_lock_and_optimistic_version_checks(): void
    {
        $student = $this->learner();
        $version = RecordVersion::of($student->fresh());
        $this->save($student, ['associate' => 'Updated'])->assertOk();
        $this->patchJson("/students/{$student->id}", [
            'version' => $version, 'associate' => 'Stale',
        ])->assertUnprocessable()->assertJsonValidationErrors('version');
        $this->actingAs(User::factory()->create())
            ->withCookie(config('session.cookie'), str_repeat('r', 40))->withHeader('X-Student-Lock', '');
        $this->get('/detail/'.$student->id)->assertOk()->assertViewHas('editLock', fn ($lock) => ! $lock['owned']);
        $this->save($student, ['phone2' => 'Forbidden'])->assertStatus(423);
        $this->assertNull($student->fresh()->phone2);
        $this->assertSame('Updated', $student->fresh()->associate);
    }

    public function test_additional_address_line_reaches_documents_without_changing_sponsor_billto(): void
    {
        $student = $this->learner();
        $this->save($student, ['streetaddress' => 'Synthetic Street', 'address_line_2' => 'Suite <200>',
            'sponsored' => false])->assertOk();
        $this->assertStringContainsString("Synthetic Street\nSuite <200>", BillingDocument::data($student->fresh())['address']);
        $this->get("/students/{$student->id}/statement")->assertOk()->assertSee('Suite &lt;200&gt;', false);
        $this->save($student, ['sponsored' => true, 'sponsor_full_address' => 'Sponsor Address'])->assertOk();
        $this->assertSame('Sponsor Address', BillingDocument::data($student->fresh())['address']);
    }
}
