<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $this->call(ReferenceDataSeeder::class);

        // کاربران اولیه — رمز از متغیر محیطی، وگرنه پیش‌فرض توسعه
        $password = env('SEED_PASSWORD', 'hana@1405');

        $users = [
            ['مدیر سامانه', 'admin@hana.local', 'admin'],
            ['کارشناس بررسی', 'expert@hana.local', 'expert'],
            ['کارشناس داده', 'data@hana.local', 'data'],
        ];

        foreach ($users as [$name, $email, $role]) {
            User::updateOrCreate(
                ['email' => $email],
                [
                    'name' => $name,
                    'role' => $role,
                    'is_active' => true,
                    'password' => Hash::make($password),
                ],
            );
        }
    }
}
