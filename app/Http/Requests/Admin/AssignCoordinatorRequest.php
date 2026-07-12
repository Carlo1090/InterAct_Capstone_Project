<?php

namespace App\Http\Requests\Admin;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AssignCoordinatorRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === 'admin';
    }

    public function rules(): array
    {
        return [
            'user_id' => [
                'required',
                'integer',
                Rule::exists('users', 'id')->where('role', 'coordinator'),
                function ($attribute, $value, $fail) {
                    $department = $this->route('department');
                    $existing = User::find($value)?->departmentsCoordinated()->first();

                    if ($existing && $existing->id !== $department->id) {
                        $fail('This coordinator is already assigned to a different department.');
                    }
                },
            ],
        ];
    }
}
