<?php

namespace Database\Seeders;

use App\Models\Program;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class StudentDemoUserSeeder extends Seeder
{
    public function run(): void
    {
        $studentProgram = Program::where('code', 'BSIT')->first();

        $student = User::updateOrCreate(
            ['email' => 'student@interntrack.local'],
            [
                'name' => 'Juan Dela Cruz',
                'password' => Hash::make('password'),
                'role' => 'student',
                'student_id_number' => '2021-IT-001',
                'program_id' => $studentProgram?->id,
                'is_active' => true,
            ]
        );

        StudentProfile::updateOrCreate(
            ['user_id' => $student->id],
            [
                'student_id_number' => '2021-IT-001',
                'middle_name' => 'Reyes',
                'date_of_birth' => '2002-05-12',
                'sex' => 'male',
                'contact_number' => '09171234567',
                'home_address' => 'Purok 4, Barangay Mabini, Tubigon, Bohol',
                'year_level' => '4th Year',
                'company_name' => 'TechPH Inc.',
                'address' => 'IT Park, Cebu City',
                'total_hours_required' => 486,
            ]
        );
    }
}
