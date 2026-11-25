<?php

namespace Tests\Feature;

use App\Billing\BillingTables;
use App\Billing\LegacyImporter;
use App\Models\AnticipatedPayment;
use App\Models\Invoice;
use App\Models\Student;
use App\Models\StudentEnrollment;
use App\Models\User;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

class LegacyImportTest extends TestCase
{
    use RefreshDatabase;

    private Connection $source;

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.connections.legacy' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true,
        ]]);
        DB::purge('legacy');
        $this->source = DB::connection('legacy');
        $migration = require database_path('migrations/2026_11_03_000002_create_billing_tables.php');
        $original = DB::getDefaultConnection();
        DB::setDefaultConnection('legacy');
        try {
            $migration->up();
        } finally {
            DB::setDefaultConnection($original);
        }
        $this->source->table('programs')->insert(['id' => 10, 'program' => 'Test', 'servicedays' => 547]);
        $this->source->table('students')->insert([
            ['id' => 20, 'first_name' => 'Renée', 'program_id' => 10, 'balance' => 99.999,
                'inactive' => null, 'start_date' => '2026-01-01 00:00:00'],
            ['id' => 21, 'first_name' => 'Other', 'program_id' => null, 'balance' => null,
                'inactive' => 0, 'start_date' => null],
        ]);
        $this->source->table('invoices')->insert(['id' => 30, 'student_id' => 21,
            'invoice_file' => "%PDF-1.4\0\xff\xfe", 'amount' => 20.555]);
        $this->source->table('studenttransactions')->insert([
            ['id' => 40, 'student_id' => 20, 'invoice_id' => 30, 'transactiontype' => 'Fee',
                'transactionamount' => 100.005],
            ['id' => 41, 'student_id' => 20, 'invoice_id' => null, 'transactiontype' => 'Payment',
                'transactionamount' => -0.006],
            ['id' => 42, 'student_id' => null, 'invoice_id' => null, 'transactiontype' => null,
                'transactionamount' => 9.99],
        ]);
        $this->source->table('loas')->insert(['id' => 50, 'student_id' => 20,
            'loa_start' => '2026-03-01', 'loa_end' => '2026-02-01']);
        $this->source->table('studentpaymentplans')->insert(['id' => 60, 'student_id' => 20]);
        $this->source->table('anticipatedpayment')->insert(['id' => 70, 'student_id' => 20,
            'studentpaymentplanid_id' => 60, 'paymentamount' => 5.555, 'paymentpercent' => 0.12345]);
    }

    protected function tearDown(): void
    {
        DB::purge('legacy');
        parent::tearDown();
    }

    private function importer(): LegacyImporter
    {
        return new LegacyImporter($this->source, DB::connection());
    }

    private function addEnrollmentHistory(int $studentId = 20): StudentEnrollment
    {
        $enrollment = StudentEnrollment::create([
            'student_id' => $studentId, 'program_id' => 10, 'mode' => 'staged',
            'started_on' => '2026-01-01',
        ]);
        $stage = DB::table('student_stage_enrollments')->insertGetId([
            'enrollment_id' => $enrollment->id, 'stage' => 1,
            'started_on' => '2026-01-01', 'service_days' => 100,
        ]);
        DB::table('enrollment_events')->insert([
            'enrollment_id' => $enrollment->id, 'stage_enrollment_id' => $stage,
            'action' => 'started', 'effective_on' => '2026-01-01', 'created_at' => now(),
        ]);
        DB::table('students')->where('id', $studentId)->update([
            'current_enrollment_id' => $enrollment->id, 'enrollment_revision' => 3,
        ]);
        $attribution = ['enrollment_id' => $enrollment->id, 'stage_enrollment_id' => $stage];
        foreach (['studenttransactions', 'loas', 'studentpaymentplans', 'anticipatedpayment'] as $table) {
            DB::table($table)->where('student_id', $studentId)->update($attribution);
        }
        DB::table('studenttransactions')->where('student_id', $studentId)->where('transactiontype', 'Fee')
            ->update(['recognition' => 'application']);
        DB::table('invoices')->insert(['student_id' => $studentId, ...$attribution]);

        return $enrollment;
    }

    public function test_import_preserves_data_and_pdfs_and_rebuilds_balances(): void
    {
        $report = $this->importer()->run();
        $this->assertSame(2, $report['tables']['students']['rows']);
        $this->assertSame(1, $report['pdfs']['count']);
        $this->assertSame("%PDF-1.4\0\xff\xfe", DB::table('invoices')->value('invoice_file'));
        $student = Student::findOrFail(20);
        $this->assertSame('100.00', $student->balance);
        $this->assertSame('Renée', $student->first_name);
        $this->assertNull($student->inactive);
        $this->assertSame('2026-01-01', $student->start_date->format('Y-m-d'));
        $this->assertFalse($student->timestamps);
        $this->assertSame(10, $student->program->id);
        $this->assertSame(2, $student->transactions->count());
        $this->assertSame(60, AnticipatedPayment::findOrFail(70)->plan->id);
        $this->assertSame('5.56', AnticipatedPayment::findOrFail(70)->paymentamount);
        $this->assertArrayNotHasKey('invoice_file', Invoice::findOrFail(30)->toArray());
        $this->assertSame(1, $report['exceptions']['unassigned_transactions']);
        $this->assertSame(1, $report['exceptions']['cross_student_invoice_links']);
        $this->assertSame(1, $report['exceptions']['loas_end_before_start']);
        $this->assertEquals(99.999, $this->source->table('students')->where('id', 20)->value('balance'));
        $this->assertSame($report['tables'], $this->importer()->run(true)['tables']);
    }

    public function test_repeat_import_refuses_to_overwrite_data(): void
    {
        $this->importer()->run();
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('populated');
        $this->importer()->run();
    }

    public function test_refresh_preview_lists_inserts_and_deletions_without_writing(): void
    {
        $this->importer()->run();
        DB::table('students')->insert(['id' => 99, 'first_name' => 'Local only']);
        $this->source->table('students')->insert(['id' => 22, 'first_name' => 'New production learner']);
        $this->source->table('students')->where('id', 20)->update(['first_name' => 'Updated']);

        $report = $this->importer()->refresh();

        $this->assertSame('refresh_preview', $report['mode']);
        $this->assertSame([99], $report['tables']['students']['removed_ids']);
        $this->assertSame(1, $report['tables']['students']['inserted_rows']);
        $this->assertSame(2, $report['tables']['students']['overwritten_rows']);
        $this->assertDatabaseHas('students', ['id' => 20, 'first_name' => 'Renée']);
        $this->assertDatabaseHas('students', ['id' => 99]);
        $this->assertDatabaseMissing('students', ['id' => 22]);
    }

    public function test_refresh_mirrors_billing_preserves_extensions_programs_and_users_and_is_repeatable(): void
    {
        $this->importer()->run();
        $user = User::factory()->create();
        DB::table('invoices')->where('id', 30)->update(['emailed_at' => '2026-11-19 12:00:00']);
        DB::table('students')->where('id', 20)->update([
            'associate' => 'Preserved', 'originalcohort' => 'Original', 'phone2' => '123',
        ]);
        DB::table('programs')->where('id', 10)->update(['program' => 'Local name', 'servicedays' => 600, 'shortname' => 'Local']);
        DB::table('programs')->insert(['id' => 99, 'program' => 'Local program', 'staged' => true,
            'certleading' => true, 'nextprog' => 0, 'certificate_program_id' => 10]);
        DB::table('students')->insert(['id' => 99, 'first_name' => 'Local only']);
        DB::table('invoices')->insert(['id' => 99, 'student_id' => 99, 'invoice_file' => 'Local PDF']);
        DB::table('studenttransactions')->insert(['id' => 99, 'student_id' => 99, 'invoice_id' => 99]);
        DB::table('loas')->insert(['id' => 99, 'student_id' => 99]);
        DB::table('studentpaymentplans')->insert(['id' => 99, 'student_id' => 99]);
        DB::table('anticipatedpayment')->insert(['id' => 99, 'student_id' => 99, 'studentpaymentplanid_id' => 99]);
        $this->source->table('programs')->insert(['id' => 11, 'program' => 'New program']);
        $this->source->table('students')->insert(['id' => 22, 'first_name' => 'New production learner', 'program_id' => 11]);
        $this->source->table('students')->where('id', 20)->update(['first_name' => 'Updated']);
        $this->source->table('invoices')->where('id', 30)->update(['invoice_file' => "%PDF-new\0\xff"]);
        $this->source->table('studenttransactions')->where('id', 40)->update(['transactionamount' => 200.005]);

        $report = $this->importer()->refresh(true);

        $this->assertSame('refresh_applied', $report['mode']);
        $this->assertDatabaseHas('students', ['id' => 20, 'first_name' => 'Updated',
            'associate' => 'Preserved', 'originalcohort' => 'Original', 'phone2' => '123']);
        $this->assertSame('200.00', Student::findOrFail(20)->balance);
        $this->assertDatabaseHas('students', ['id' => 22, 'program_id' => 11]);
        foreach (['students', 'invoices', 'studenttransactions', 'loas', 'studentpaymentplans', 'anticipatedpayment'] as $table) {
            $this->assertDatabaseMissing($table, ['id' => 99]);
        }
        $this->assertSame("%PDF-new\0\xff", DB::table('invoices')->where('id', 30)->value('invoice_file'));
        $this->assertNull(DB::table('invoices')->where('id', 30)->value('emailed_at'));
        $this->assertDatabaseHas('programs', ['id' => 10, 'program' => 'Local name', 'servicedays' => 600, 'shortname' => 'Local']);
        $this->assertDatabaseHas('programs', ['id' => 99, 'staged' => true, 'certleading' => true,
            'nextprog' => 0, 'certificate_program_id' => 10]);
        $this->assertDatabaseHas('users', ['id' => $user->id, 'password' => $user->password]);
        $this->assertSame($report['tables'], $this->importer()->refresh(true)['tables']);
    }

    public function test_refresh_refuses_to_delete_students_referenced_by_laravel_only_tables(): void
    {
        $this->importer()->run();
        DB::table('students')->insert(['id' => 99, 'first_name' => 'Workflow learner']);
        DB::table('workflow_runs')->insert([
            'workflow' => 'test', 'subject_type' => 'test', 'subject_id' => 1,
            'student_id' => 99, 'status' => 'active',
        ]);
        $this->source->table('students')->where('id', 20)->update(['first_name' => 'Must not apply']);
        try {
            $this->importer()->refresh(true);
            $this->fail('Refresh must protect workflow references, even with SET NULL foreign keys.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('workflow_runs references local-only students', $exception->getMessage());
        }

        $this->assertDatabaseHas('students', ['id' => 20, 'first_name' => 'Renée']);
        $this->assertDatabaseHas('workflow_runs', ['student_id' => 99]);
    }

    public function test_refresh_refuses_source_id_collisions_with_local_stage_programs_before_resetting_history(): void
    {
        $this->importer()->run();
        $enrollment = $this->addEnrollmentHistory();
        DB::table('programs')->insert(['id' => 99, 'program' => 'Local stage', 'staged' => true]);
        $this->source->table('programs')->insert(['id' => 99, 'program' => 'Different production program']);
        $this->source->table('students')->where('id', 20)->update(['first_name' => 'Must not apply']);
        try {
            $this->importer()->refresh(true);
            $this->fail('Colliding stage program IDs must prevent the refresh.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('Source program IDs collide', $exception->getMessage());
        }
        $this->assertDatabaseHas('students', ['id' => 20, 'first_name' => 'Renée', 'current_enrollment_id' => $enrollment->id]);
        $this->assertDatabaseHas('programs', ['id' => 99, 'program' => 'Local stage', 'staged' => true]);
        $this->assertDatabaseHas('student_enrollments', ['id' => $enrollment->id]);
    }

    public function test_refresh_verification_failure_rolls_back_updates_inserts_and_deletions(): void
    {
        $this->importer()->run();
        DB::table('invoices')->where('id', 30)->update(['emailed_at' => '2026-11-19 12:00:00']);
        $enrollment = $this->addEnrollmentHistory();
        $stage = $enrollment->stages()->firstOrFail();
        DB::table('transaction_stage_allocations')->insert(['transaction_id' => 40,
            'stage_enrollment_id' => $stage->id, 'amount' => 100]);
        DB::table('finalgrades')->insert(['studentid' => 20, 'program_id' => 10,
            'enrollment_id' => $enrollment->id, 'enrollment_key' => 'enrollment:'.$enrollment->id]);
        DB::table('students')->insert(['id' => 99, 'first_name' => 'Local only']);
        $this->source->table('students')->where('id', 20)->update(['first_name' => 'Updated']);
        $this->source->table('students')->insert(['id' => 22, 'first_name' => 'New']);
        try {
            $this->importer()->refresh(true, function (string $message) {
                if ($message === 'Refreshed anticipatedpayment') {
                    DB::table('invoices')->where('id', 30)->update(['invoice_file' => 'Corrupt']);
                }
            });
            $this->fail('Expected PDF verification failure');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('Verification failed for invoices', $exception->getMessage());
        }
        $this->assertDatabaseHas('students', ['id' => 20, 'first_name' => 'Renée']);
        $this->assertDatabaseHas('students', ['id' => 99]);
        $this->assertDatabaseMissing('students', ['id' => 22]);
        $this->assertSame("%PDF-1.4\0\xff\xfe", DB::table('invoices')->value('invoice_file'));
        $this->assertSame('2026-11-19 12:00:00', DB::table('invoices')->where('id', 30)->value('emailed_at'));
        $this->assertDatabaseHas('students', ['id' => 20, 'current_enrollment_id' => $enrollment->id, 'enrollment_revision' => 3]);
        $this->assertDatabaseCount('student_enrollments', 1);
        $this->assertDatabaseCount('student_stage_enrollments', 1);
        $this->assertDatabaseCount('enrollment_events', 1);
        $this->assertDatabaseHas('studenttransactions', ['id' => 40, 'enrollment_id' => $enrollment->id, 'recognition' => 'application']);
        $this->assertDatabaseHas('transaction_stage_allocations', ['transaction_id' => 40, 'stage_enrollment_id' => $stage->id, 'amount' => 100]);
        $this->assertDatabaseHas('finalgrades', ['enrollment_id' => $enrollment->id, 'enrollment_key' => 'enrollment:'.$enrollment->id]);
    }

    public function test_refresh_refuses_an_empty_source_or_active_student_lock(): void
    {
        $this->importer()->run();
        $user = User::factory()->create();
        DB::table('staff_sessions')->insert(['id' => 'session', 'user_id' => $user->id, 'last_activity' => time()]);
        DB::table('student_edit_locks')->insert([
            'student_id' => 20, 'session_id' => 'session', 'token' => 'token',
            'acquired_at' => time(), 'last_activity' => time(),
        ]);
        try {
            $this->importer()->refresh(true);
            $this->fail('Expected lock refusal');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('Student editing locks exist', $exception->getMessage());
        }
        foreach (array_reverse(array_keys(BillingTables::MONEY)) as $table) {
            $this->source->table($table)->delete();
        }
        $this->expectExceptionMessage('Source students table is empty');
        $this->importer()->refresh();
    }

    public function test_import_failure_rolls_back_every_table(): void
    {
        try {
            $this->importer()->run(false, function (string $message) {
                if ($message === 'Imported invoices') {
                    throw new RuntimeException('Injected failure');
                }
            });
            $this->fail('Expected injected failure');
        } catch (RuntimeException $exception) {
            $this->assertSame('Injected failure', $exception->getMessage());
        }
        foreach (array_keys(BillingTables::MONEY) as $table) {
            $this->assertDatabaseCount($table, 0);
        }
    }

    public function test_verification_detects_pdf_corruption(): void
    {
        $this->importer()->run();
        DB::table('invoices')->where('id', 30)->update(['invoice_file' => 'broken']);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Verification failed for invoices');
        $this->importer()->run(true);
    }

    public function test_verification_detects_balance_corruption(): void
    {
        $this->importer()->run();
        DB::table('students')->where('id', 20)->update(['balance' => 1]);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Balance verification failed');
        $this->importer()->run(true);
    }

    public function test_same_database_is_refused_before_writes(): void
    {
        $db = DB::connection();
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('same database');
        (new LegacyImporter($db, $db))->run();
    }

    public function test_schema_mismatch_is_refused_before_writes(): void
    {
        $this->source->getSchemaBuilder()->table('programs', function (Blueprint $table) {
            $table->string('unexpected')->nullable();
        });
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Schema mismatch');
        $this->importer()->run();
    }

    public function test_destination_only_access_fields_are_left_null_and_not_compared_with_django(): void
    {
        $report = $this->importer()->run();
        $this->assertSame(BillingTables::destinationOnly()['students'],
            $report['destination_only_student_fields']);
        foreach ([...BillingTables::STUDENT_ADDITIONS, ...BillingTables::STUDENT_HISTORY] as $field) {
            $this->assertNull(Student::findOrFail(20)->getAttribute($field));
        }
        DB::table('students')->where('id', 20)->update([
            'associate' => 'Owner-imported value', 'access_status_code' => 3, 'originalcohort' => 'Original cohort',
        ]);
        $this->assertSame($report['tables'], $this->importer()->run(true)['tables']);
        $this->assertSame('Owner-imported value', Student::findOrFail(20)->associate);
        $this->assertSame('Original cohort', Student::findOrFail(20)->originalcohort);
    }

    public function test_destination_only_program_fields_are_left_null_and_not_compared_with_django(): void
    {
        $report = $this->importer()->run();
        $this->assertSame(BillingTables::PROGRAM_ADDITIONS, $report['destination_only_program_fields']);
        $this->assertNull(DB::table('programs')->where('id', 10)->value('type'));
        $this->assertNull(DB::table('programs')->where('id', 10)->value('shortname'));
        foreach (['staged', 'certleading', 'nextprog', 'certificate_program_id'] as $field) {
            $this->assertNull(DB::table('programs')->where('id', 10)->value($field));
        }
        DB::table('programs')->where('id', 10)->update(['type' => str_repeat('t', 8), 'shortname' => str_repeat('s', 32)]);
        $this->assertSame($report['tables'], $this->importer()->run(true)['tables']);
        $this->assertSame(str_repeat('s', 32), DB::table('programs')->where('id', 10)->value('shortname'));
    }

    public function test_programs_may_be_renamed_and_added_but_legacy_ids_and_servicedays_are_protected(): void
    {
        $this->importer()->run();
        DB::table('programs')->where('id', 10)->update(['program' => 'Test Full', 'programdesc' => 'Test Full Certificate']);
        DB::table('programs')->insert(['id' => 11, 'program' => 'New', 'servicedays' => 100]);
        $programs = $this->importer()->run(true)['tables']['programs'];
        $this->assertSame(['legacy_rows' => 1, 'rows' => 2, 'edited' => [10 => [
            'program' => ['legacy' => 'Test', 'current' => 'Test Full'],
            'programdesc' => ['legacy' => null, 'current' => 'Test Full Certificate'],
        ]], 'added_ids' => [11]], $programs);

        DB::table('programs')->where('id', 10)->update(['servicedays' => 548]);
        try {
            $this->importer()->run(true);
            $this->fail('Changed servicedays must fail verification.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('servicedays changed for legacy program 10', $exception->getMessage());
        }
        DB::table('programs')->where('id', 10)->update(['servicedays' => 547]);
        $this->source->table('programs')->insert(['id' => 12, 'program' => 'Only in Django', 'servicedays' => 1]);
        $this->expectExceptionMessage('legacy program 12 is missing');
        $this->importer()->run(true);
    }

    public function test_unrecognized_destination_student_columns_still_refuse_import(): void
    {
        DB::connection()->getSchemaBuilder()->table('students', function (Blueprint $table) {
            $table->string('unexpected')->nullable();
        });
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Schema mismatch for students');
        $this->importer()->run();
    }

    public function test_refresh_previews_then_discards_test_enrollment_history_and_can_repeat(): void
    {
        $this->importer()->run();
        $user = User::factory()->create();
        DB::table('program_stage_definitions')->insert(['program_id' => 10, 'stage' => 1, 'service_days' => 100]);
        $enrollment = $this->addEnrollmentHistory();
        $stage = $enrollment->stages()->firstOrFail();
        $invoiceId = DB::table('invoices')->where('student_id', 20)->value('id');
        DB::table('invoice_stage_allocations')->insert(['invoice_id' => $invoiceId,
            'stage_enrollment_id' => $stage->id, 'amount' => 100]);
        DB::table('transaction_stage_allocations')->insert(['transaction_id' => 40,
            'stage_enrollment_id' => $stage->id, 'amount' => 100]);
        $this->source->table('students')->where('id', 20)->update(['first_name' => 'Fresh source']);
        $counts = ['enrollment_events' => 1, 'student_stage_enrollments' => 1, 'student_enrollments' => 1];
        $preview = $this->importer()->refresh();
        $this->assertSame($counts, $preview['enrollment_reset']);
        $this->assertSame(['invoice_stage_allocations' => 1, 'transaction_stage_allocations' => 1], $preview['allocation_reset']);
        $this->assertDatabaseCount('invoice_stage_allocations', 1);
        $this->assertDatabaseHas('students', ['id' => 20, 'first_name' => 'Renée',
            'current_enrollment_id' => $enrollment->id, 'enrollment_revision' => 3]);
        $this->assertDatabaseHas('studenttransactions', ['id' => 40, 'enrollment_id' => $enrollment->id, 'recognition' => 'application']);
        $report = $this->importer()->refresh(true);
        $this->assertSame($counts, $report['enrollment_reset']);
        $this->assertDatabaseCount('invoice_stage_allocations', 0);
        $this->assertDatabaseCount('transaction_stage_allocations', 0);
        $this->assertDatabaseHas('students', ['id' => 20, 'first_name' => 'Fresh source',
            'current_enrollment_id' => null, 'enrollment_revision' => 0]);
        foreach (array_keys($counts) as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        foreach (['studenttransactions', 'invoices', 'loas', 'studentpaymentplans', 'anticipatedpayment'] as $table) {
            $this->assertFalse(DB::table($table)->whereNotNull('enrollment_id')->orWhereNotNull('stage_enrollment_id')->exists(), $table);
        }
        $this->assertNull(DB::table('studenttransactions')->where('id', 40)->value('recognition'));
        $this->assertDatabaseHas('program_stage_definitions', ['program_id' => 10, 'stage' => 1, 'service_days' => 100]);
        $this->assertDatabaseHas('users', ['id' => $user->id, 'password' => $user->password]);
        $this->addEnrollmentHistory();
        $this->assertSame($counts, $this->importer()->refresh(true)['enrollment_reset']);
        $this->assertDatabaseCount('student_enrollments', 0);
    }

    public function test_refresh_preserves_separate_grade_sheets_without_leaving_enrollment_foreign_keys(): void
    {
        $this->importer()->run();
        $first = $this->addEnrollmentHistory();
        $second = StudentEnrollment::create(['student_id' => 20, 'program_id' => 10, 'mode' => 'whole',
            'started_on' => '2026-11-01', 'service_days' => 100]);
        foreach ([$first, $second] as $enrollment) {
            DB::table('finalgrades')->insert(['studentid' => 20, 'program_id' => 10,
                'enrollment_id' => $enrollment->id, 'enrollment_key' => 'enrollment:'.$enrollment->id]);
            DB::table('formsdata')->insert(['form_type' => 'graduation-initiation', 'student_id' => 20,
                'program_id' => 10, 'data' => '{}', 'enrollment_id' => $enrollment->id,
                'enrollment_key' => 'enrollment:'.$enrollment->id]);
        }
        $this->importer()->refresh(true);
        $this->assertDatabaseCount('finalgrades', 2);
        $this->assertDatabaseCount('formsdata', 2);
        foreach ([$first, $second] as $enrollment) {
            foreach (['finalgrades', 'formsdata'] as $table) {
                $this->assertDatabaseHas($table, ['enrollment_id' => null, 'enrollment_key' => 'refresh:'.$enrollment->id]);
            }
        }
        $this->assertDatabaseCount('student_enrollments', 0);
        $this->importer()->refresh(true);
        $this->assertDatabaseCount('finalgrades', 2);
    }

    public function test_refresh_can_remove_local_only_students_with_discardable_enrollment_history(): void
    {
        $this->importer()->run();
        DB::table('students')->insert(['id' => 99, 'first_name' => 'Test learner']);
        $this->addEnrollmentHistory(99);

        $this->importer()->refresh(true);

        $this->assertDatabaseMissing('students', ['id' => 99]);
        $this->assertDatabaseCount('student_enrollments', 0);
        $this->assertDatabaseCount('student_stage_enrollments', 0);
        $this->assertDatabaseCount('enrollment_events', 0);
    }

    public function test_refresh_command_cannot_reset_a_production_environment(): void
    {
        $this->app->instance('env', 'production');
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Billing refresh is restricted to APP_ENV=local.');
        $this->artisan('billing:refresh', ['--apply' => true])->run();
    }

    public function test_invoice_deletion_unlinks_without_deleting_ledger_entries(): void
    {
        $this->importer()->run();
        Invoice::findOrFail(30)->delete();
        $this->assertDatabaseCount('studenttransactions', 3);
        $this->assertDatabaseHas('studenttransactions', ['id' => 40, 'invoice_id' => null]);
    }

    public function test_student_deletion_is_restricted_when_financial_history_exists(): void
    {
        $this->importer()->run();
        $this->expectException(QueryException::class);
        Student::findOrFail(20)->delete();
    }
}
