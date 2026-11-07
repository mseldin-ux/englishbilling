<?php

namespace Tests\Feature;

use App\Billing\BillingTables;
use App\Billing\LegacyImporter;
use App\Models\AnticipatedPayment;
use App\Models\Invoice;
use App\Models\Student;
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
        $this->assertSame([...BillingTables::STUDENT_ADDITIONS, ...BillingTables::STUDENT_HISTORY],
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
        $this->assertSame(['type', 'shortname', 'director_team_email', 'course_completion_survey_url', 'employer_survey_url', 'employment_form_url'], $report['destination_only_program_fields']);
        $this->assertNull(DB::table('programs')->where('id', 10)->value('type'));
        $this->assertNull(DB::table('programs')->where('id', 10)->value('shortname'));
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
