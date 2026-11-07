<?php

namespace App\Billing;

use App\Models\Student;
use Illuminate\Database\Eloquent\Builder;

final class StudentSearch
{
    public const STATUSES = ['active', 'extension', 'grad', 'ingroup', 'loa', 'open',
        'pif', 'spon', 'sus', 'wd', 'flag'];

    public static function rules(): array
    {
        return [
            'query' => ['nullable', 'string', 'max:255'],
            'showall' => ['sometimes', 'boolean'],
            'graduation_date' => ['nullable', 'date_format:Y-m-d'],
            'past_due_start' => ['nullable', 'date_format:Y-m-d'],
            'past_due_end' => ['nullable', 'date_format:Y-m-d'],
            'payment_type' => ['nullable', 'string', 'max:255'],
            'transaction_type' => ['nullable', 'string', 'max:255'],
            'cohort' => ['nullable', 'string', 'max:255'],
            'statuses' => ['sometimes', 'array'],
            'statuses.*' => ['in:'.implode(',', self::STATUSES)],
            'page' => ['sometimes', 'integer', 'min:1'],
        ];
    }

    public static function query(array $filters): Builder
    {
        $query = Student::query();
        if (empty($filters['showall'])) {
            $query->where(fn (Builder $q) => $q->where('archived', false)->orWhereNull('archived'));
        }
        if (isset($filters['query']) && $filters['query'] !== '') {
            $term = $filters['query'];
            if (ctype_digit($term)) {
                $query->where('id', $term);
            } else {
                $query->where(function (Builder $q) use ($term) {
                    foreach (['first_name', 'last_name', 'city', 'state', 'country',
                        'school', 'studentcomments', 'student_notes', 'emailaddr'] as $field) {
                        $q->orWhere($field, 'like', '%'.$term.'%');
                    }
                });
            }
        }
        foreach (['payment_type' => 'pmt_type', 'cohort' => 'cohort'] as $input => $field) {
            if (! empty($filters[$input])) {
                $query->where($field, 'like', '%'.$filters[$input].'%');
            }
        }
        if (! empty($filters['graduation_date'])) {
            $query->whereDate('graddate', $filters['graduation_date']);
        }
        foreach (['past_due_start' => '>=', 'past_due_end' => '<='] as $input => $operator) {
            if (! empty($filters[$input])) {
                $query->whereDate('next_due_date', $operator, $filters[$input]);
            }
        }
        if (isset($filters['transaction_type']) && $filters['transaction_type'] !== '') {
            $type = $filters['transaction_type'];
            if ($type === '0') {
                $query->where(fn (Builder $q) => $q->whereDoesntHave('transactions')
                    ->orWhereHas('transactions', fn (Builder $t) => $t->whereNull('transactiontype')->orWhere('transactiontype', '')));
            } else {
                $query->whereHas('transactions', fn (Builder $q) => $q->where('transactiontype', 'like', '%'.$type.'%'));
            }
        }
        $flags = ['extension' => 'onextension', 'grad' => 'graduated', 'ingroup' => 'in_group',
            'loa' => 'loa', 'pif' => 'pif', 'spon' => 'sponsored', 'sus' => 'sus', 'wd' => 'wd'];
        foreach ($filters['statuses'] ?? [] as $status) {
            if ($status === 'active') {
                foreach (['inactive', 'loa', 'wd', 'sus', 'graduated'] as $field) {
                    $query->where($field, false);
                }
            } elseif ($status === 'open') {
                $query->where('pif', false);
            } elseif ($status === 'flag') {
                $query->where('flag', '>', 1);
            } else {
                $query->where($flags[$status], true);
            }
        }

        return $query;
    }
}
