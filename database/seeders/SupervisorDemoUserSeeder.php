<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\CompanySupervisor;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class SupervisorDemoUserSeeder extends Seeder
{
    public function run(): void
    {
        $company = Company::firstOrCreate(
            ['name' => 'TechPH Inc.'],
            [
                'address' => 'IT Park, Cebu City',
                'location' => 'Cebu City, Cebu',
                'industry' => 'Technology',
                'is_active' => true,
            ]
        );

        $supervisor = User::updateOrCreate(
            ['email' => 'mdcsupervisor@gmail.com'],
            [
                'name' => 'Engr. Ramon Villanueva',
                'password' => Hash::make('password'),
                'role' => 'supervisor',
                'is_active' => true,
            ]
        );

        CompanySupervisor::firstOrCreate(
            ['company_id' => $company->id, 'user_id' => $supervisor->id],
            ['position' => 'Senior Software Engineer']
        );
    }
}
