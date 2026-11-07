<?php

namespace App\Billing;

use App\Models\Student;

final class RecordsFields
{
    public static function fields(): array
    {
        $existing = array_merge(...array_values(StudentFields::GROUPS));
        $names = ['first_name', 'middle_name', 'last_name', 'maiden_name', 'access_status_code',
            'access_change_date', 'cohort', 'birth_date', 'sponsored', 'self_pay',
            'combination_payment', 'streetaddress', 'address_line_2', 'city', 'state',
            'zipcode', 'country', 'phone', 'phonelabel', 'phone2', 'phone2label',
            'emailaddr', 'self_directed', 'comp_college', 'associate'];
        $fields = array_intersect_key([...$existing, ...StudentFields::ACCESS_FIELDS], array_flip($names));
        $fields['sponsored'] = ['School Sponsored', 'nullable_boolean'];

        return array_replace(array_fill_keys($names, []), $fields);
    }

    public static function values(Student $student): array
    {
        $values = [];
        foreach (self::fields() as $name => $field) {
            $raw = $student->getRawOriginal($name);
            $values[$name] = match ($field[1]) {
                'datetime' => $raw ? str_replace(' ', 'T', substr($raw, 0, 19)) : null,
                'date' => $raw ? substr($raw, 0, 10) : null,
                default => $raw,
            };
        }

        return $values;
    }
}
