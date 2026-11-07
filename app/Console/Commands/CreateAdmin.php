<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class CreateAdmin extends Command
{
    protected $signature = 'billing:create-admin';

    protected $description = 'Interactively create an admin login without placing a password in shell history';

    public function handle(): int
    {
        $data = [
            'username' => $this->ask('Username'),
            'name' => $this->ask('Display name'),
            'password' => $this->secret('Password (at least 12 characters)'),
            'password_confirmation' => $this->secret('Confirm password'),
        ];
        $validated = Validator::make($data, [
            'username' => ['required', 'string', 'max:150', 'alpha_dash:ascii', Rule::unique('users')],
            'name' => ['required', 'string', 'max:255'],
            'password' => ['required', 'confirmed', Password::min(12)],
        ])->validate();
        User::create([...$validated, 'is_admin' => true]);
        $this->info('Admin created.');

        return self::SUCCESS;
    }
}
