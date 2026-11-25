<?php

namespace App\Billing;

use App\Models\Invoice;
use App\Models\Student;
use App\Models\StudentTransaction;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class RecordVersion
{
    private static array $columns = [];

    public static function of(Model $model): string
    {
        $key = $model->getConnectionName().':'.$model->getConnection()->getDatabaseName().':'.$model->getTable();
        $columns = self::$columns[$key] ??= $model->getConnection()->getSchemaBuilder()->getColumnListing($model->getTable());
        $attributes = array_fill_keys($columns, null);
        foreach ($model->getRawOriginal() as $field => $value) {
            if (! array_key_exists($field, $attributes)) {
                continue;
            }
            $cast = $model->getCasts()[$field] ?? '';
            $attributes[$field] = match (true) {
                $value === null => null,
                $cast === 'boolean' => (string) (int) $value,
                str_starts_with($cast, 'decimal:') => Money::cents($value),
                default => (string) $value,
            };
        }
        unset($attributes['invoice_file'], $attributes['has_pdf'], $attributes['emailed_at']);
        if ($model instanceof Student) {
            unset($attributes['balance']);
        }
        if (config('billing.concurrent_stages') && $model->exists
            && ($model instanceof Invoice || $model instanceof StudentTransaction)) {
            $invoice = $model instanceof Invoice;
            $attributes['_stage_allocations'] = DB::table($invoice ? 'invoice_stage_allocations' : 'transaction_stage_allocations')
                ->where($invoice ? 'invoice_id' : 'transaction_id', $model->id)
                ->orderBy('stage_enrollment_id')->get(['stage_enrollment_id', 'amount'])->toArray();
        }
        ksort($attributes);

        return hash('sha256', serialize($attributes));
    }

    public static function check(Model $model, string $version): void
    {
        if (! hash_equals(self::of($model), $version)) {
            throw ValidationException::withMessages([
                'version' => 'This record changed since you loaded it. Reload and review the latest values before saving.',
            ]);
        }
    }
}
