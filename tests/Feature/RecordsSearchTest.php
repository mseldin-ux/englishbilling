<?php

namespace Tests\Feature;

use App\Billing\RecordVersion;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RecordsSearchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['access_level' => 'records']));
        DB::table('students')->insert([
            ['id' => 101, 'first_name' => 'Alpha', 'city' => 'Boston', 'access_status_code' => 3,
                'self_pay' => true, 'birth_date' => '1990-01-01', 'access_change_date' => '2026-11-04 23:59:59'],
            ['id' => 102, 'first_name' => 'Beta', 'city' => 'Boston', 'access_status_code' => 7,
                'self_pay' => false, 'birth_date' => '1990-12-31', 'access_change_date' => '2026-11-05 00:00:00'],
            ['id' => 103, 'first_name' => 'Gamma', 'city' => 'Chicago', 'access_status_code' => null,
                'self_pay' => null, 'birth_date' => null, 'access_change_date' => null],
        ]);
    }

    private function url(array $filters, string $path = '/records'): string
    {
        return $path.'?'.http_build_query(['filters' => $filters]);
    }

    public function test_filters_use_and_and_exports_use_the_same_conditions(): void
    {
        $filters = [
            ['field' => 'city', 'match' => 'contains', 'value' => 'Boston'],
            ['field' => 'access_status_code', 'match' => 'exact', 'value' => '3'],
            ['field' => 'id', 'match' => 'range', 'value' => '101', 'end' => '102'],
        ];
        $response = $this->get($this->url($filters))->assertOk()->assertSee('1 matching rows')
            ->assertSee('Alpha')->assertDontSee('Beta')->assertDontSee('Gamma')->assertSee('View');
        $this->assertCount(3, $response->viewData('filters'));
        $text = $this->get($this->url($filters, '/records/export'))->assertOk()->streamedContent();
        $this->assertStringContainsString('User ID', $text);
        $this->assertStringContainsString("101\tAlpha", $text);
        $this->assertStringNotContainsString('Beta', $text);
    }

    public function test_dates_include_entire_end_day_and_nulls_are_distinct_from_false(): void
    {
        $this->get($this->url([['field' => 'birth_date', 'match' => 'range',
            'value' => '1990-01-01', 'end' => '1990-12-31']]))->assertOk()->assertSee('2 matching rows');
        $this->get($this->url([['field' => 'access_change_date', 'match' => 'range',
            'value' => '2026-11-04', 'end' => '2026-11-04']]))->assertOk()
            ->assertSee('Alpha')->assertDontSee('Beta');
        foreach ([
            ['self_pay', 'exact', '0', 'Beta'],
            ['self_pay', 'exact', '1', 'Alpha'],
            ['self_pay', 'blank', null, 'Gamma'],
            ['self_pay', 'exact', null, 'Gamma'],
            ['access_status_code', 'blank', null, 'Gamma'],
            ['access_status_code', 'exact', null, 'Gamma'],
        ] as [$field, $match, $value, $name]) {
            $this->get($this->url([compact('field', 'match', 'value')]))->assertOk()
                ->assertSee('1 matching rows')->assertSee($name);
        }
    }

    public function test_contextual_validation_rejects_invalid_filters(): void
    {
        foreach ([
            ['field' => 'id', 'match' => 'range', 'value' => '103', 'end' => '101'],
            ['field' => 'birth_date', 'match' => 'exact', 'value' => '2026-02-30'],
            ['field' => 'self_pay', 'match' => 'contains', 'value' => '1'],
            ['field' => 'access_status_code', 'match' => 'exact', 'value' => '12'],
            ['field' => 'id', 'match' => 'exact', 'value' => '1.5'],
            ['field' => 'balance', 'match' => 'exact', 'value' => '0'],
        ] as $filter) {
            $this->getJson($this->url([$filter]))->assertUnprocessable();
        }
    }

    public function test_id_is_displayed_but_not_editable_and_records_checkboxes_submit_false(): void
    {
        $response = $this->get('/records/103/edit')->assertOk()->assertSee('103');
        $this->assertStringContainsString('type="checkbox" name="self_pay"', $response->getContent());
        $this->assertStringContainsString('type="hidden" name="self_pay" value="0"', $response->getContent());
        $this->assertStringNotContainsString('name="id"', $response->getContent());
        $this->withCredentials()->withCookie(config('session.cookie'), app('session')->getId())
            ->withHeader('X-Student-Lock', $response->viewData('editLock')['token']);
        $version = RecordVersion::of(Student::findOrFail(103));
        $this->patchJson('/records/103', ['version' => $version, 'id' => 999])->assertUnprocessable();
        $this->patchJson('/records/103', ['version' => $version, 'self_pay' => '0'])->assertOk();
        $this->assertFalse(Student::findOrFail(103)->self_pay);
        $this->assertNull(Student::findOrFail(103)->combination_payment);
    }
}
