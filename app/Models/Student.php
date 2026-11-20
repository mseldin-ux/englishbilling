<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Student extends BillingModel
{
    protected $table = 'students';

    protected $attributes = ['enrollment_revision' => 0];

    protected function accessChangeDate(): Attribute
    {
        return Attribute::make(
            set: fn ($value) => $value === null ? null : CarbonImmutable::parse($value)->format('Y-m-d H:i:s.u'),
        );
    }

    public function displayName(): string
    {
        return implode(' ', array_filter([$this->first_name, $this->middle_name, $this->last_name],
            fn ($value) => $value !== null && $value !== '' && strtolower($value) !== 'none'));
    }

    public function status(): string
    {
        foreach (['inactive' => 'Inactive', 'loa' => 'LOA', 'wd' => 'WD', 'sus' => 'Sus',
            'graduated' => 'Grad', 'onextension' => 'Ext'] as $field => $label) {
            if ($this->$field) {
                return $label;
            }
        }

        return 'Active';
    }

    protected function casts(): array
    {
        $casts = ['balance' => 'decimal:2', 'payment_amount_due' => 'decimal:2',
            'birth_date' => 'immutable_date', 'access_change_date' => 'immutable_datetime',
            'access_status_code' => 'integer'];
        foreach (['inactive', 'loa', 'pif', 'wd', 'sus', 'in_group', 'graduated',
            'onextension', 'sponsored', 'archived', 'transferred',
            'self_pay', 'combination_payment', 'self_directed', 'comp_college'] as $field) {
            $casts[$field] = 'boolean';
        }
        foreach (['graddate', 'actualdategrad', 'next_due_date', 'inactive_date',
            'loa_date', 'pifdate', 'wd_date', 'sus_date', 'start_date', 'extension_start_date'] as $field) {
            $casts[$field] = 'immutable_datetime';
        }

        return $casts;
    }

    public function program(): BelongsTo
    {
        return $this->belongsTo(Program::class);
    }

    public function enrollments(): HasMany
    {
        return $this->hasMany(StudentEnrollment::class)->orderBy('id');
    }

    public function currentEnrollment(): BelongsTo
    {
        return $this->belongsTo(StudentEnrollment::class, 'current_enrollment_id');
    }

    public function stageSummary(): string
    {
        $enrollment = $this->currentEnrollment;
        if (! $enrollment || $enrollment->mode !== 'staged') {
            return '';
        }
        $stage = $enrollment->stages->last();
        if (! $stage) {
            return 'Awaiting stage enrollment';
        }
        if ($enrollment->status === 'graduated') {
            return 'Graduated';
        }

        return 'Stage '.$stage->stage.($stage->completed_on ? ' done' : ' — '.$stage->status.' since '.$stage->started_on->format('Y-m-d'));
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(StudentTransaction::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    public function loas(): HasMany
    {
        return $this->hasMany(LeaveOfAbsence::class);
    }

    public function paymentPlans(): HasMany
    {
        return $this->hasMany(StudentPaymentPlan::class);
    }

    public function anticipatedPayments(): HasMany
    {
        return $this->hasMany(AnticipatedPayment::class);
    }
}
