<?php

namespace Tests\Feature;

use App\Billing\Enrollments;
use App\Billing\RecordVersion;
use App\Models\Program;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class EnrollmentFlagTest extends TestCase
{
    use RefreshDatabase;

    public function test_both_edit_views_describe_every_student_checkbox(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $student = new Student;
        $student->forceFill(['first_name' => 'Example'])->save();
        foreach (['/detail/'.$student->id, '/records/'.$student->id.'/edit'] as $url) {
            $document = new \DOMDocument;
            $response = $this->get($url)->assertOk()
                ->assertSee('financial users&#039; browse view', false);
            @$document->loadHTML($response->getContent());
            $xpath = new \DOMXPath($document);
            $checkboxes = $xpath->query('//input[@type="checkbox" and starts-with(@id, "student-field-")]');
            $this->assertGreaterThan(0, $checkboxes->length);
            foreach ($checkboxes as $checkbox) {
                $id = $checkbox->getAttribute('id');
                $helpId = $checkbox->getAttribute('aria-describedby');
                $this->assertNotSame('', $helpId, $id);
                $this->assertCount(1, $xpath->query('//label[@for="'.$id.'"]'));
                $help = $xpath->query('//*[@role="tooltip" and @id="'.$helpId.'"]');
                $this->assertCount(1, $help);
                $this->assertNotEmpty(trim($help->item(0)->textContent));
                $this->assertCount(1, $xpath->query('//*[@tabindex="0" and @aria-describedby="'.$helpId.'"]'));
            }
            if (str_starts_with($url, '/detail/')) {
                $this->assertCount(1, $xpath->query('//div[contains(concat(" ", normalize-space(@class), " "), " field-row ") and not(contains(concat(" ", normalize-space(@class), " "), " help-align-end "))]/input[@id="student-field-archived"]'));
            }
        }
    }

    public function test_creation_defaults_and_browse_visibility(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $this->get('/create')->assertSee('name="hasenrolled" value="1" checked', false);
        $this->post('/students', ['first_name' => 'Enrolled'])->assertRedirect();
        $this->post('/records', ['first_name' => 'Intake', 'last_name' => 'Learner', 'city' => 'SecretCity'])->assertRedirect();
        $intake = Student::where('first_name', 'Intake')->firstOrFail();
        $this->assertFalse($intake->hasenrolled);
        $this->assertTrue(Student::where('first_name', 'Enrolled')->firstOrFail()->hasenrolled);
        $this->get('/records')->assertSee('Intake')->assertSee('Has enrolled');
        $this->get('/browse?reset=1')->assertDontSee('Intake')->assertSee('Enrolled');
        $this->get('/browse?query=Intake+Learner')->assertSee('Intake');
        $this->get('/browse?query='.$intake->id)->assertSee('Intake');
        $this->get('/browse?query=SecretCity')->assertDontSee('Intake');
        $this->get('/browse?include_unenrolled=1')->assertSee('Intake')->assertSee('Enrolled');
        $this->get('/records/create')->assertSee('name="hasenrolled" value="1"', false)
            ->assertDontSee('name="hasenrolled" value="1" checked', false);
        $this->postJson('/records', ['hasenrolled' => null])->assertUnprocessable()->assertJsonValidationErrors('hasenrolled');
        $this->postJson('/students', ['hasenrolled' => null])->assertUnprocessable()->assertJsonValidationErrors('hasenrolled');
    }

    public function test_detail_validates_merged_record_and_records_view_can_edit_intake(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $program = new Program;
        $program->forceFill(['program' => 'Example', 'servicedays' => 100])->save();
        $student = new Student;
        $student->forceFill(['hasenrolled' => false, 'first_name' => 'Intake'])->save();
        $this->obtainEditingLock($student);
        $url = '/students/'.$student->id;
        $save = fn (array $data) => $this->patchJson($url, ['version' => RecordVersion::of($student->fresh()), ...$data]);
        $save(['hasenrolled' => true])->assertUnprocessable()->assertJsonValidationErrors(['program_id', 'start_date', 'graddate']);
        $save(['hasenrolled' => true, 'program_id' => $program->id, 'start_date' => '2026-01-01', 'graddate' => '2027-01-01'])->assertOk();
        $save(['inactive' => true, 'loa' => true, 'wd' => true, 'sus' => true, 'onextension' => true,
            'in_group' => true, 'sponsored' => true])->assertUnprocessable()
            ->assertJsonValidationErrors(['inactive_date', 'loa_date', 'wd_date', 'sus_date', 'extension_start_date',
                'group_name', 'sponsor_contact', 'sponsor_email']);
        $save(['graddate' => '2026-01-01', 'actualdategrad' => '2025-12-31',
            'inactive_date' => '2026-01-01', 'loa_date' => '2026-01-01', 'wd_date' => '2026-01-01',
            'sus_date' => '2026-01-01', 'extension_start_date' => '2026-01-01'])
            ->assertUnprocessable()->assertJsonValidationErrors(['graddate', 'actualdategrad', 'inactive_date', 'loa_date', 'wd_date', 'sus_date', 'extension_start_date']);
        $save(['inactive' => true, 'inactive_date' => '2026-01-02', 'in_group' => true, 'group_name' => 'Group',
            'sponsored' => true, 'sponsor_contact' => 'Contact', 'sponsor_email' => 'contact@example.org'])->assertOk();
        $save(['first_name' => 'Edited'])->assertOk();
        $student->forceFill(['sponsor_contact' => null])->save();
        $save(['first_name' => 'Blocked'])->assertUnprocessable()->assertJsonValidationErrors('sponsor_contact');
        $this->patchJson('/records/'.$student->id, ['version' => RecordVersion::of($student->fresh()), 'first_name' => 'Brief edit'])->assertOk();
    }

    public function test_leave_dates_and_overlaps_are_student_wide(): void
    {
        $this->actingAs(User::factory()->create());
        $student = new Student;
        $student->forceFill(['start_date' => '2026-01-01'])->save();
        $this->obtainEditingLock($student);
        $url = '/students/'.$student->id.'/loas';
        $this->postJson($url, ['loa_start' => '2026-01-01', 'loa_end' => '2026-01-02'])->assertUnprocessable()->assertJsonValidationErrors('loa_start');
        $this->postJson($url, ['loa_start' => '2026-02-01', 'loa_end' => '2026-02-01'])->assertUnprocessable()->assertJsonValidationErrors('loa_end');
        $this->postJson($url, ['loa_start' => '2026-02-01', 'loa_end' => '2026-02-10'])->assertOk();
        $this->postJson($url, ['loa_start' => '2026-02-09', 'loa_end' => '2026-02-12'])->assertUnprocessable()->assertJsonValidationErrors('loa_start');
        $this->postJson($url, ['loa_start' => '2026-02-10', 'loa_end' => null])->assertOk();
        $this->postJson($url, ['loa_start' => '2026-03-01', 'loa_end' => null])->assertUnprocessable()->assertJsonValidationErrors('loa_start');
        $student->forceFill(['start_date' => null])->save();
        $this->postJson($url, ['loa_start' => '2026-01-03'])->assertUnprocessable()->assertJsonValidationErrors('loa_start');
    }

    public function test_graduation_requires_enrollment_but_enrollment_may_be_cleared_afterwards(): void
    {
        $this->actingAs(User::factory()->create());
        $this->post('/students', ['hasenrolled' => false, 'graduated' => true])->assertSessionHasErrors('graduated');
        $student = new Student;
        $student->forceFill(['hasenrolled' => false, 'start_date' => '2026-01-01'])->save();
        $this->obtainEditingLock($student);
        $this->patchJson('/students/'.$student->id, ['version' => RecordVersion::of($student->fresh()), 'graduated' => true])
            ->assertUnprocessable()->assertJsonValidationErrors('graduated');
        try {
            Enrollments::validateGraduation($student, '2026-02-01');
            $this->fail('Graduation of an unenrolled learner must be rejected.');
        } catch (ValidationException $e) {
            $this->assertNotEmpty($e->errors());
        }
        $student->forceFill(['hasenrolled' => true, 'graduated' => true, 'actualdategrad' => '2026-02-01'])->save();
        $this->patchJson('/students/'.$student->id, ['version' => RecordVersion::of($student->fresh()), 'hasenrolled' => false])->assertOk();
        $this->assertTrue($student->fresh()->graduated);
        $this->assertFalse($student->fresh()->hasenrolled);
    }

    public function test_migration_marks_preexisting_and_database_default_records_enrolled(): void
    {
        $migration = require database_path('migrations/2026_11_24_000001_add_hasenrolled_to_students.php');
        $migration->down();
        $id = DB::table('students')->insertGetId(['first_name' => 'Before migration']);
        $migration->up();
        $this->assertEquals(1, DB::table('students')->where('id', $id)->value('hasenrolled'));
        $id = DB::table('students')->insertGetId(['first_name' => 'Imported']);
        $this->assertEquals(1, DB::table('students')->where('id', $id)->value('hasenrolled'));
    }
}
