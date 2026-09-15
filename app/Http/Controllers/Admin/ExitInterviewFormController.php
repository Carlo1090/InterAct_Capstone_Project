<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Support\ExitInterview\ExitInterviewForms;
use App\Support\ExitInterviewFormLayout;
use Illuminate\Http\JsonResponse;

/**
 * Admin → Exit Interview: the catalogue of hardcoded exit interview forms,
 * read-only. Each department is assigned one of these (on the Departments
 * page, when it is created or edited); this page exists so the admin can see
 * what each form actually asks and which departments currently use it,
 * without opening a student's copy.
 */
class ExitInterviewFormController extends Controller
{
    public function index(): JsonResponse
    {
        $departments = Department::orderBy('code')->get(['id', 'code', 'name', 'exit_interview_form']);

        $forms = [];

        foreach (ExitInterviewForms::all() as $form) {
            $forms[] = $form->toArray() + [
                'reference' => $form->reference,
                'pages' => ExitInterviewFormLayout::for($form)->document()['pages'],
                'departments' => $departments
                    ->where('exit_interview_form', $form->key)
                    ->values()
                    ->map(fn (Department $department) => [
                        'id' => $department->id,
                        'code' => $department->code,
                        'name' => $department->name,
                    ]),
            ];
        }

        return response()->json([
            'forms' => $forms,
            'default' => ExitInterviewForms::DEFAULT,
        ]);
    }
}
