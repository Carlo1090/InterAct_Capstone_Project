<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreCompanyRequest;
use App\Http\Requests\Admin\UpdateCompanyRequest;
use App\Models\Company;
use App\Models\Department;
use App\Models\SystemLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CompanyController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $companies = Company::query()
            ->when(
                $request->filled('search'),
                fn ($query) => $query->where('name', 'like', '%'.$request->string('search').'%')
            )
            ->when(
                $request->filled('status'),
                fn ($query) => $query->where('is_active', $request->query('status') === 'active')
            )
            ->when(
                $request->filled('department_id'),
                fn ($query) => $query->whereHas(
                    'batchStudents.batch.program',
                    fn ($q) => $q->where('department_id', $request->integer('department_id'))
                )
            )
            ->withCount([
                'batchStudents as active_interns_count' => fn ($query) => $query->where('status', 'active'),
                'batchStudents as total_interns_count',
            ])
            ->orderBy('name')
            ->paginate(20);

        return response()->json($companies);
    }

    public function show(Company $company): JsonResponse
    {
        $company->loadCount([
            'batchStudents as active_interns_count' => fn ($query) => $query->where('status', 'active'),
            'batchStudents as total_interns_count',
        ]);
        $company->load('supervisors.user');

        $departments = Department::whereHas(
            'programs.batches.batchStudents',
            fn ($query) => $query->where('company_id', $company->id)
        )->orderBy('name')->get(['id', 'code', 'name']);

        $company->setAttribute('departments', $departments);

        return response()->json($company);
    }

    public function store(StoreCompanyRequest $request): JsonResponse
    {
        $company = Company::create([
            ...$request->validated(),
            'is_active' => $request->boolean('is_active', true),
        ]);

        SystemLog::record('Company Created', "Added partner company {$company->name}");

        return response()->json($company, 201);
    }

    public function update(UpdateCompanyRequest $request, Company $company): JsonResponse
    {
        $company->update($request->validated());

        SystemLog::record('Company Updated', "Updated company {$company->name}");

        return response()->json($company);
    }
}
