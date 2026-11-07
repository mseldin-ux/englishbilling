<?php

namespace App\Billing;

final class StudentFields
{
    public const GROUPS = [
        'Learner and contact information' => [
            'cohort' => ['Cohort', 'text', 255],
            'first_name' => ['First Name', 'text', 255],
            'last_name' => ['Last Name', 'text', 255],
            'middle_name' => ['Middle', 'text', 255],
            'emailaddr' => ['Email', 'text', 255],
            'streetaddress' => ['Address', 'text', 255],
            'city' => ['City', 'text', 255],
            'state' => ['State', 'text', 255],
            'zipcode' => ['Zip', 'text', 15],
            'phone' => ['Phone', 'text', 32],
            'phonelabel' => ['Phone Label', 'text', 255],
            'phone2' => ['Phone 2', 'text', 32],
            'phone2label' => ['Phone 2 Label', 'text', 255],
            'country' => ['Country', 'text', 255],
            'school' => ['School', 'text', 255],
            'start_date' => ['Start Date', 'date'],
            'graddate' => ['Expected Grad Date', 'date'],
            'graduated' => ['Graduated', 'checkbox'],
            'actualdategrad' => ['Actual Grad Date', 'date'],
            'stcode' => ['Student Type Code', 'text', 8],
            'program_id' => ['Program', 'program'],
            'stid' => ['Student ID', 'text', 32],
            'gender' => ['Gender', 'text', 16],
        ],
        'Payment and account information' => [
            'pif' => ['Paid in full', 'checkbox'],
            'pifdate' => ['Date PIF', 'date'],
            'payment_amount_due' => ['Next Pmt $', 'money'],
            'next_due_date' => ['Next Pmt Date', 'date'],
            'invoice_notes' => ['Invoice notes', 'textarea'],
            'invoicenumber' => ['Invoice Reference', 'text', 255],
            'flag' => ['ACCOUNT FLAGGED', 'flag'],
            'archived' => ['Archived', 'checkbox'],
            'pmt_type' => ['Pmt Type', 'text', 255],
            'delivery_method' => ['Delivery Method', 'text', 255],
        ],
        'Status and comments' => [
            'studentcomments' => ['Comments', 'textarea'],
            'inactive' => ['Inactive', 'checkbox'],
            'inactive_date' => ['Inactive date', 'date'],
            'loa' => ['LOA?', 'checkbox'],
            'loa_date' => ['Leave of absence date', 'date'],
            'wd' => ['Withdrew?', 'checkbox'],
            'wd_date' => ['Withdrawal date', 'date'],
            'sus' => ['Suspended?', 'checkbox'],
            'sus_date' => ['Suspended date', 'date'],
            'onextension' => ['On Extension', 'checkbox'],
            'extension_start_date' => ['Extension Start Date', 'date'],
            'in_group' => ['In group', 'checkbox'],
            'group_name' => ['Group name', 'text', 255],
            'transferred' => ['Transferred', 'checkbox'],
        ],
        'Sponsor and notes' => [
            'sponsored' => ['Sponsored', 'checkbox'],
            'sponsor_contact' => ['Sponsor contact', 'text', 255],
            'sponsor_full_address' => ['Sponsor full address', 'text', 255],
            'sponsor_email' => ['Sponsor email', 'text', 128],
            'sponsor_phone' => ['Sponsor phone', 'text', 64],
            'sponsorship_note' => ['Sponsorship Note', 'textarea'],
            'student_notes' => ['Other Notes', 'textarea'],
        ],
    ];

    public const ACCESS_FIELDS = [
        'maiden_name' => ['Maiden Name', 'text', 255],
        'birth_date' => ['Birth Date', 'date'],
        'address_line_2' => ['Address Line 2', 'text', 255],
        'access_status_code' => ['Records Status (Access)', 'access_status'],
        'access_change_date' => ['Access Change Date', 'datetime'],
        'self_pay' => ['Self Pay', 'nullable_boolean'],
        'combination_payment' => ['Combination Payment', 'nullable_boolean'],
        'self_directed' => ['Self Directed', 'nullable_boolean'],
        'comp_college' => ['Comp College', 'nullable_boolean'],
        'associate' => ['Associate', 'text', 255],
    ];

    public static function rules(): array
    {
        $rules = [];
        foreach ([...self::GROUPS, self::ACCESS_FIELDS] as $fields) {
            foreach ($fields as $name => $field) {
                $rules[$name] = match ($field[1]) {
                    'date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
                    'datetime' => ['sometimes', 'nullable', 'date_format:Y-m-d\TH:i,Y-m-d\TH:i:s,Y-m-d\TH:i:s.u'],
                    'checkbox', 'nullable_boolean' => ['sometimes', 'nullable', 'boolean'],
                    'access_status' => ['sometimes', 'nullable', 'integer', 'exists:student_access_statuses,id'],
                    'money' => ['sometimes', 'nullable', ...BillingRules::money()],
                    'program' => ['sometimes', 'nullable', 'integer', 'exists:programs,id'],
                    'flag' => ['sometimes', 'nullable', 'integer', 'between:0,65535'],
                    default => ['sometimes', 'nullable', 'string', 'max:'.($field[2] ?? 65535)],
                };
            }
        }

        return $rules;
    }
}
