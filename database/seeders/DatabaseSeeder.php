<?php

namespace Database\Seeders;

use App\Enums\Role;
use App\Models\User;
use App\Support\Mobile;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $mobile = Mobile::normalize((string) config('tuka.admin.mobile'));
        if (Mobile::isValid($mobile)) {
            User::updateOrCreate(
                ['mobile' => $mobile],
                ['name' => config('tuka.admin.name') ?: 'مدیر سامانه', 'role' => Role::Manager, 'is_active' => true],
            );
        }

        if (config('tuka.test_admin_enabled') && ! app()->environment('production')) {
            $this->call(DemoSeeder::class);
        }
    }
}
