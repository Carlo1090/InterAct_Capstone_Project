<?php

namespace Database\Seeders;

use App\Models\Department;
use App\Models\Program;
use Illuminate\Database\Seeder;

class ProgramSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * Seeds the degree programs under each department. CAST -> BSIT is the
     * one we know is correct for this project's actual use case; the rest
     * are placeholders and should be reviewed before final demo/defense.
     */
    public function run(): void
    {
        $cast = Department::where('code', 'CAST')->first();

        if ($cast) {
            Program::firstOrCreate(
                ['code' => 'BSIT'],
                ['department_id' => $cast->id, 'name' => 'Bachelor of Science in Information Technology']
            );
            Program::firstOrCreate(
                ['code' => 'BSCS'],
                ['department_id' => $cast->id, 'name' => 'Bachelor of Science in Computer Science']
            );
        }
    }
}
