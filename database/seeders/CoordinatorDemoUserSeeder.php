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
        $bsitProgram = Program::where('code', 'BSIT')->first();

        $coordinator = User::updateOrCreate(
            ['email' => 'mdccore@gmail.com'],
            [
                'name' => 'Prof. Alicia Montoya',
                'username' => 'mdccore',
                'password' => Hash::make('password'),
                'role' => 'coordinator',
                'program_id' => $coordinatorProgram?->id,
                'is_active' => true,
                // DTR on for the CAST/BSIT coordinator only, so a fresh seed
                // has one department that demonstrates the QR/geofence Daily
                // Time Record and the CABM ones that demonstrate opting out
                // (their interns have no single fixed workplace). No geofence
                // is seeded — a supervisor must create one from their real
                // location, which is the flow worth demonstrating.
                'dtr_enabled' => true,
            ]
        );

        if ($bsitProgram) {
            $coordinator->departmentsCoordinated()->syncWithoutDetaching([$bsitProgram->department_id]);
        }
    }
}
