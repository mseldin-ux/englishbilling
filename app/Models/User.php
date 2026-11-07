<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'username', 'email', 'password', 'is_admin', 'access_level'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    public const LEVELS = [
        'staff' => 'Staff',
        'billing_no_reports' => 'Billing Staff (No Reports)',
        'records' => 'records',
        'forms' => 'forms',
    ];

    protected $attributes = ['access_level' => 'staff'];

    public function canAccessBilling(): bool
    {
        return $this->is_admin || in_array($this->access_level, ['staff', 'billing_no_reports'], true);
    }

    public function canUseRecordsWorkspace(): bool
    {
        return $this->canAccessBilling() || $this->access_level === 'records';
    }

    public function canViewReports(): bool
    {
        return $this->is_admin || $this->access_level === 'staff';
    }

    public function canManagePaymentPlans(): bool
    {
        return $this->is_admin || $this->access_level === 'staff';
    }

    public function level(): string
    {
        return $this->is_admin ? 'admin' : $this->access_level;
    }

    public function levelLabel(): string
    {
        return $this->is_admin ? 'Admin' : self::LEVELS[$this->access_level];
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_admin' => 'boolean',
        ];
    }
}
