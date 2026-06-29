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
     * Seeds all 7 confirmed degree programs across the 3 departments:
     *
     * CAST    -> BSIT
     * CABM-B  -> BSBA-FM, BSBA-MM, BSBA-OM, BSA
     * CABM-H  -> BSTM, BSHRM
     */
    public function run(): void
    {
        $programsByDepartment = [
            'CAST' => [
                ['code' => 'BSIT', 'name' => 'Bachelor of Science in Information Technology'],
            ],
            'CABM-B' => [
                ['code' => 'BSBA-FM', 'name' => 'Bachelor of Science in Business Administration in Financial Management'],
                ['code' => 'BSBA-MM', 'name' => 'Bachelor of Science in Business Administration in Marketing Management'],
                ['code' => 'BSBA-OM', 'name' => 'Bachelor of Science in Business Administration in Operational Management'],
                ['code' => 'BSA', 'name' => 'Bachelor of Science in Accountancy'],
            ],
            'CABM-H' => [
                ['code' => 'BSTM', 'name' => 'Bachelor of Science in Tourism Management'],
                ['code' => 'BSHRM', 'name' => 'Bachelor of Science in Hotel and Restaurant Management'],
            ],
        ];

        foreach ($programsByDepartment as $departmentCode => $programs) {
            $department = Department::where('code', $departmentCode)->first();

            if (! $department) {
                continue;
            }

            foreach ($programs as $program) {
                Program::firstOrCreate(
                    ['department_id' => $department->id, 'code' => $program['code']],
                    ['name' => $program['name']]
                );
            }
        }
    }
}
