<?php

namespace App\Billing;

use App\Models\Student;
use Illuminate\Database\Eloquent\Model;
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
        unset($attributes['invoice_file'], $attributes['has_pdf']);
        if ($model instanceof Student) {
            unset($attributes['balance']);
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
