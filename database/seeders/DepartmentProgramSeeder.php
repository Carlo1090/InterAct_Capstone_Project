<?php

namespace Database\Seeders;

use App\Models\Department;
use App\Models\Program;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Cache;

class DepartmentProgramSeeder extends Seeder
{
    public function run(): void
    {
        // `code` is the STABLE IDENTIFIER — every seeder and lookup in the
        // project keys off it (`Department::where('code', 'CABM-B')`), it is
        // what fits a narrow table column, and it is what prints on the SIPP
        // forms. Never rename one casually.
        //
        // `name` is DISPLAY ONLY, and until 2026-09-08 it was seeded to the
        // very same string as the code — so the Admin → Departments tab showed
        // "CABM-B / CABM-B" in two columns with nothing saying what either was
        // for, and the mobile card read "CABM-B · CABM-B". Worse than
        // cosmetic: two official documents (BuildsWeeklyActivityLogPdf's
        // DEFAULT_DEPARTMENT_LINE and the GROUP info sheet's header) carry
        // HARDCODED literals precisely because `departments.name` could not be
        // trusted to hold a real name.
        $departments = [
            'CAST' => [
                'name' => 'College of Arts, Sciences and Technology',
                'programs' => [
                    ['code' => 'BSIT', 'name' => 'BSIT'],
                ],
            ],
            // THE DISTINGUISHING WORD LEADS, and that is not a stylistic
            // preference. Written college-first, CABM-B and CABM-H both open
            // with the same 48 characters and truncate to an identical
            // "College of Accountancy..." in the Departments list — the two
            // rows became indistinguishable at any normal column width, which
            // is the very confusion this change set out to remove. Caught on
            // screen, not in theory. It also matches how the app actually
            // models these: PROJECT.md's Domain Facts are explicit that CABM-B
            // and CABM-H are two INDEPENDENT top-level departments, not
            // sub-units of one CABM, and the SIPP forms print the college and
            // the unit as two separate lines.
            'CABM-B' => [
                'name' => 'Business Department – College of Accountancy, Business and Management',
                'programs' => [
                    ['code' => 'BSBA-FM', 'name' => 'BSBA-FM'],
                    ['code' => 'BSBA-MM', 'name' => 'BSBA-MM'],
                    ['code' => 'BSBA-OM', 'name' => 'BSBA-OM'],
                    ['code' => 'BSA', 'name' => 'BSA'],
                ],
            ],
            'CABM-H' => [
                'name' => 'Hospitality Department – College of Accountancy, Business and Management',
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

        // `Admin\DepartmentController::index()` and `ProgramController::index()`
        // cache these lists for a DAY and invalidate only at their own write
        // points — which a seeder writing straight through Eloquent never
        // reaches. Without this, `php artisan db:seed --class=DepartmentProgramSeeder`
        // (the documented way to correct reference data on a running install)
        // appears to do nothing: the database is right and every admin page
        // keeps serving the previous names until the TTL lapses. Found exactly
        // that way on 2026-09-08, correcting the department names.
        //
        // `migrate:fresh --seed` was never affected, because it drops the
        // `cache` table along with everything else — which is precisely why
        // this went unnoticed.
        Cache::forget('reference:departments');
        Cache::forget('reference:programs');
    }
}
