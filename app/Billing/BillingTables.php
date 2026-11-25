<?php

namespace App\Billing;

final class BillingTables
{
    public const STUDENT_HISTORY = ['originalcohort'];

    public const PROGRAM_ADDITIONS = ['type', 'shortname', 'director_team_email', 'course_completion_survey_url', 'employer_survey_url', 'employment_form_url',
        'staged', 'certleading', 'nextprog', 'certificate_program_id'];

    public static function destinationOnly(): array
    {
        return [
            'programs' => self::PROGRAM_ADDITIONS,
            'students' => [...self::STUDENT_ADDITIONS, ...self::STUDENT_HISTORY, 'current_enrollment_id', 'enrollment_revision', 'hasenrolled'],
            'studenttransactions' => ['enrollment_id', 'stage_enrollment_id', 'recognition'],
            'invoices' => ['enrollment_id', 'stage_enrollment_id', 'emailed_at'],
            'studentpaymentplans' => ['enrollment_id', 'stage_enrollment_id'],
            'anticipatedpayment' => ['enrollment_id', 'stage_enrollment_id'],
            'loas' => ['enrollment_id', 'stage_enrollment_id'],
        ];
    }

    public const STUDENT_ADDITIONS = [
        'maiden_name', 'address_line_2', 'associate', 'phonelabel', 'phone2label',
        'phone2', 'birth_date', 'access_change_date', 'self_pay',
        'combination_payment', 'self_directed', 'comp_college', 'access_status_code',
    ];

    public const MONEY = [
        'programs' => [],
        'globals' => [],
        'students' => ['payment_amount_due', 'balance'],
        'invoices' => ['amount'],
        'studenttransactions' => ['transactionamount'],
        'loas' => [],
        'paymentplans' => [],
        'studentpaymentplans' => [],
        'anticipatedpayment' => ['paymentamount'],
    ];
}
