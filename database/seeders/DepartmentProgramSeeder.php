<?php

namespace Database\Seeders;

use App\Models\Department;
use App\Models\Program;
use Illuminate\Database\Seeder;

class DepartmentProgramSeeder extends Seeder
{
    public function run(): void
    {
        $departments = [
            'CAST' => [
                'name' => 'CAST',
                'programs' => [
                    ['code' => 'BSIT', 'name' => 'BSIT'],
                ],
            ],
            'CABM-B' => [
                'name' => 'CABM-B',
                'programs' => [
                    ['code' => 'BSBA-FM', 'name' => 'BSBA-FM'],
                    ['code' => 'BSBA-MM', 'name' => 'BSBA-MM'],
                    ['code' => 'BSBA-OM', 'name' => 'BSBA-OM'],
                    ['code' => 'BSA', 'name' => 'BSA'],
                ],
            ],
            'CABM-H' => [
                'name' => 'CABM-H',
                'programs' => [
                    ['code' => 'BSTM', 'name' => 'BSTM'],
                    ['code' => 'BSHRM', 'name' => 'BSHRM'],
                ],
            ],
        ];

        Department::whereNotIn('code', array_keys($departments))->delete();

        foreach ($departments as $code => $departmentData) {
            $department = Department::firstOrCreate(
                ['code' => $code],
                ['name' => $departmentData['name']]
            );

            $department->update(['name' => $departmentData['name']]);

            $programCodes = array_column($departmentData['programs'], 'code');

            Program::where('department_id', $department->id)
                ->whereNotIn('code', $programCodes)
                ->delete();

            foreach ($departmentData['programs'] as $programData) {
                $program = Program::firstOrCreate(
                    [
                        'department_id' => $department->id,
                        'code' => $programData['code'],
                    ],
                    ['name' => $programData['name']]
                );

                $program->update(['name' => $programData['name']]);
            }
        }
    }
}
