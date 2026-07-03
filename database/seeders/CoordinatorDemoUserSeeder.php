<?php

namespace Database\Seeders;

use App\Models\Program;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class CoordinatorDemoUserSeeder extends Seeder
{
    public function run(): void
    {
        $coordinatorProgram = Program::where('code', 'BSBA-FM')->first();

        User::updateOrCreate(
            ['email' => 'coordinator@interntrack.local'],
            [
                'name' => 'Prof. Alicia Montoya',
                'password' => Hash::make('password'),
                'role' => 'coordinator',
                'program_id' => $coordinatorProgram?->id,
                'is_active' => true,
            ]
        );
    }
}
