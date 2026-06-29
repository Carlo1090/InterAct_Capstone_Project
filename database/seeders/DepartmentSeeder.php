<?php

namespace Database\Seeders;

use App\Models\Department;
use Illuminate\Database\Seeder;

class DepartmentSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * Seeds the three departments that participate in InternTrack, per the
     * confirmed department coverage:
     * - CAST  — College of Arts, Sciences and Technology
     * - CABM-B — College of Accountancy, Business and Management (Business)
     * - CABM-H — College of Accountancy, Business and Management (Hospitality)
     *
     * CABM-B and CABM-H are modeled as two independent departments rather
     * than one department with sub-units, since each has its own distinct
     * program list and is referenced as its own code throughout the
     * project's documentation. If a shared "CABM" umbrella grouping is ever
     * needed (e.g. for a combined report), that can be added later without
     * touching this structure.
     */
    public function run(): void
    {
        $departments = [
            ['name' => 'College of Arts, Sciences and Technology', 'code' => 'CAST'],
            ['name' => 'College of Accountancy, Business and Management - Business', 'code' => 'CABM-B'],
            ['name' => 'College of Accountancy, Business and Management - Hospitality', 'code' => 'CABM-H'],
        ];

        foreach ($departments as $department) {
            Department::firstOrCreate(['code' => $department['code']], $department);
        }
    }
}
