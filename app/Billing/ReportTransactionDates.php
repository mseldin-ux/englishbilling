<?php

namespace App\Billing;

use Illuminate\Database\Query\Builder;
use Illuminate\Validation\ValidationException;

final class ReportTransactionDates
{
    public static function requireOurDate(Builder $query, string $dateColumn = 'processeddate', string $idColumn = 'id'): void
    {
        $row = (clone $query)->reorder()->select($idColumn)->whereNull($dateColumn)->first();
        if ($row !== null) {
            $id = $row->id;
            throw ValidationException::withMessages(['report' => "Transaction $id has no Our Date. Correct it before generating this dated report."]);
        }
    }
}
