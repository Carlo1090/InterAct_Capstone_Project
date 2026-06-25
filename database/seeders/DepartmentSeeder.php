<?php

namespace Database\Seeders;

use App\Models\Department;
use Illuminate\Database\Seeder;

class DepartmentSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * Seeds the departments at Mater Dei College that participate in the
     * internship program. Update/extend this list to match your institution's
     * actual department roster before your final defense.
     */
    public function run(): void
    {
        $departments = [
            ['name' => 'College of Arts, Sciences and Technology', 'code' => 'CAST'],
            ['name' => 'College of Business Administration', 'code' => 'CBA'],
            ['name' => 'College of Education', 'code' => 'COED'],
        ];

        foreach ($departments as $department) {
            Department::firstOrCreate(['code' => $department['code']], $department);
        }
    }
}
