<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            DepartmentProgramSeeder::class,
        ]);

        // Dedicated system/admin user (id=1) for auto-triggered actions like
        // weekly compilation and email reminders, per the schema's
        // implementation notes for the system_logs table.
        User::firstOrCreate(
            ['email' => 'system@interntrack.local'],
            [
                'name' => 'System',
                'password' => Hash::make('change-this-password'),
                'role' => 'admin',
                'is_active' => true,
            ]
        );

        // Test admin account for local development login.
        User::firstOrCreate(
            ['email' => 'admin@interntrack.local'],
            [
                'name' => 'Test Admin',
                'password' => Hash::make('password'),
                'role' => 'admin',
                'is_active' => true,
            ]
        );

        $this->call(StudentDemoUserSeeder::class);
        $this->call(CoordinatorDemoUserSeeder::class);
        $this->call(SupervisorDemoUserSeeder::class);
    }
}
