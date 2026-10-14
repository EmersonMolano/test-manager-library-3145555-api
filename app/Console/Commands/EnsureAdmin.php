<?php

namespace App\Console\Commands;

use App\Models\LibraryUser;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class EnsureAdmin extends Command
{
    protected $signature = 'library:ensure-admin';
    protected $description = 'Provision the administrator from environment credentials';

    public function handle(): int
    {
        $username = strtolower(trim((string) env('ADMIN_USERNAME', 'admin')));
        $password = (string) env('ADMIN_PASSWORD', 'change-me-now');
        LibraryUser::updateOrCreate(
            ['username' => $username],
            ['id' => (string) (LibraryUser::where('username', $username)->value('id') ?: Str::uuid()), 'password_hash' => Hash::make($password), 'role' => 'admin'],
        );
        $this->info('Administrator provisioned.');
        return self::SUCCESS;
    }
}
