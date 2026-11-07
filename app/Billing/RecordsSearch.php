<?php

namespace App\Billing;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class RecordsSearch
{
    public static function fields(): array
    {
        return ['id' => ['User ID', 'integer'], ...RecordsFields::fields()];
    }

    public static function filters(Request $request): array
    {
        $data = $request->validate([
            'filters' => ['sometimes', 'array', 'max:20'],
            'filters.*' => ['array:field,match,value,end'],
            'filters.*.field' => ['required', Rule::in(array_keys(self::fields()))],
            'filters.*.match' => ['required', 'string'],
            'filters.*.value' => ['nullable', 'string', 'max:255'],
            'filters.*.end' => ['nullable', 'string', 'max:255'],
            'field' => ['nullable', Rule::in(array_keys(self::fields()))],
            'match' => ['nullable', 'string'],
            'value' => ['nullable', 'string', 'max:255'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);
        $filters = $data['filters'] ?? (! empty($data['field']) ? [[
            'field' => $data['field'], 'match' => $data['match'] ?? 'contains', 'value' => $data['value'] ?? null,
        ]] : []);
        foreach ($filters as $index => $filter) {
            $type = self::fields()[$filter['field']][1];
            $matches = match ($type) {
                'integer', 'date', 'datetime' => ['exact', 'range', 'blank'],
                'nullable_boolean', 'access_status' => ['exact', 'blank'],
                default => ['contains', 'exact', 'blank'],
            };
            if (! in_array($filter['match'], $matches, true)) {
                throw ValidationException::withMessages(["filters.$index.match" => 'This comparison is not available for the selected field.']);
            }
            if ($filter['match'] === 'blank') {
                continue;
            }
            if (in_array($type, ['nullable_boolean', 'access_status'], true)
                && ($filter['value'] ?? null) === null) {
                continue;
            }
            $rule = match ($type) {
                'integer' => ['integer', 'min:1'],
                'date', 'datetime' => ['date_format:Y-m-d'],
                'access_status' => ['integer', 'exists:student_access_statuses,id'],
                'nullable_boolean' => ['in:0,1'],
                default => ['string', 'max:255'],
            };
            validator(['filters' => [$index => $filter]], [
                "filters.$index.value" => ['required', ...$rule],
                ...($filter['match'] === 'range' ? ["filters.$index.end" => ['required', ...$rule]] : []),
            ])->validate();
            if ($filter['match'] === 'range' && ($type === 'integer'
                ? (int) $filter['end'] < (int) $filter['value'] : $filter['end'] < $filter['value'])) {
                throw ValidationException::withMessages(["filters.$index.end" => 'Range end must be on or after its start.']);
            }
        }

        return array_values($filters);
    }

    public static function apply(Builder $query, array $filters): Builder
    {
        foreach ($filters as $filter) {
            $field = $filter['field'];
            $type = self::fields()[$field][1];
            if ($filter['match'] === 'blank' || (in_array($type, ['nullable_boolean', 'access_status'], true)
                && ($filter['value'] ?? null) === null)) {
                $type === 'text'
                    ? $query->where(fn ($q) => $q->whereNull($field)->orWhere($field, ''))
                    : $query->whereNull($field);
            } elseif (in_array($type, ['date', 'datetime'], true)) {
                if ($filter['match'] === 'range') {
                    $query->whereDate($field, '>=', $filter['value'])->whereDate($field, '<=', $filter['end']);
                } else {
                    $query->whereDate($field, $filter['value']);
                }
            } elseif ($filter['match'] === 'range') {
                $query->whereBetween($field, [(int) $filter['value'], (int) $filter['end']]);
            } elseif ($filter['match'] === 'exact') {
                $query->where($field, $filter['value']);
            } else {
                $escaped = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($filter['value']));
                $query->whereRaw("LOWER(CAST({$field} AS CHAR)) LIKE ? ESCAPE '!'", ['%'.$escaped.'%']);
            }
        }

        return $query;
    }
}
