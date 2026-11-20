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
            'program_id' => ['nullable', 'integer', 'exists:programs,id'],
            'stage' => ['nullable', 'integer', 'between:1,3'],
            'start_from' => ['nullable', 'date_format:Y-m-d'],
            'start_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:start_from'],
            'stage_start_from' => ['nullable', 'date_format:Y-m-d'],
            'stage_start_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:stage_start_from'],
            'statuses' => ['sometimes', 'array'],
            'statuses.*' => ['in:'.implode(',', self::STATUSES)],
            'page' => ['sometimes', 'integer', 'min:1'],
        ];
    }

    public static function query(array $filters): Builder
    {
        $query = Student::query();
        $stageFilter = fn ($q) => $q
            ->when(! empty($filters['stage']), fn ($q) => $q->where('stage', $filters['stage']))
            ->when(! empty($filters['start_from']), fn ($q) => $q->whereDate('started_on', '>=', $filters['start_from']))
            ->when(! empty($filters['start_to']), fn ($q) => $q->whereDate('started_on', '<=', $filters['start_to']))
            ->when(! empty($filters['stage_start_from']), fn ($q) => $q->whereDate('started_on', '>=', $filters['stage_start_from']))
            ->when(! empty($filters['stage_start_to']), fn ($q) => $q->whereDate('started_on', '<=', $filters['stage_start_to']));
        $hasStage = ! empty($filters['stage']) || ! empty($filters['stage_start_from']) || ! empty($filters['stage_start_to']);
        if (! empty($filters['program_id']) || $hasStage || ! empty($filters['start_from']) || ! empty($filters['start_to'])) {
            $query->where(function (Builder $matches) use ($filters, $hasStage, $stageFilter) {
                if (! $hasStage) {
                    $matches->where(function (Builder $legacy) use ($filters) {
                        $legacy->whereNull('current_enrollment_id')
                            ->when(! empty($filters['program_id']), fn ($q) => $q->where('program_id', $filters['program_id']))
                            ->when(! empty($filters['start_from']), fn ($q) => $q->whereDate('start_date', '>=', $filters['start_from']))
                            ->when(! empty($filters['start_to']), fn ($q) => $q->whereDate('start_date', '<=', $filters['start_to']));
                    });
                }
                $matches->orWhereHas('enrollments', function (Builder $enrollments) use ($filters, $hasStage, $stageFilter) {
                    $enrollments->when(! empty($filters['program_id']), fn ($q) => $q->where('program_id', $filters['program_id']));
                    if ($hasStage) {
                        $enrollments->whereHas('stages', $stageFilter);
                    }
                    if (! $hasStage && (! empty($filters['start_from']) || ! empty($filters['start_to']))) {
                        $dateFilter = fn ($q) => $q
                            ->when(! empty($filters['start_from']), fn ($q) => $q->whereDate('started_on', '>=', $filters['start_from']))
                            ->when(! empty($filters['start_to']), fn ($q) => $q->whereDate('started_on', '<=', $filters['start_to']));
                        $enrollments->where(fn ($q) => $dateFilter($q)->orWhereHas('stages', $dateFilter));
                    }
                });
            });
        }
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
