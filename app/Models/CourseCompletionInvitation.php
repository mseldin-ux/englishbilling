<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Crypt;

class CourseCompletionInvitation extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['token_hash', 'token_encrypted'];

    protected function casts(): array
    {
        return ['expires_at' => 'immutable_datetime', 'revoked_at' => 'immutable_datetime',
            'last_opened_at' => 'immutable_datetime', 'student_id' => 'integer', 'program_id' => 'integer'];
    }

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    public static function findByToken(string $token): ?self
    {
        return preg_match('/^[A-Za-z0-9]{64}$/', $token) === 1 ? self::where('token_hash', self::hash($token))->first() : null;
    }

    public function token(): string
    {
        return Crypt::decryptString($this->token_encrypted);
    }

    public function url(): string
    {
        return config('workflows.portal_url').route('portal.completion.show', $this->token(), false);
    }

    public function usable(): bool
    {
        return $this->revoked_at === null && $this->expires_at->isFuture();
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function program(): BelongsTo
    {
        return $this->belongsTo(Program::class);
    }

    public function submissions(): HasMany
    {
        return $this->hasMany(CourseCompletion::class, 'invitation_id');
    }
}
