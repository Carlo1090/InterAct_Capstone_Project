<?php

namespace App\Http\Requests\Coordinator;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === 'coordinator';
    }

    public function rules(): array
    {
        // A student's intended batch must be one this coordinator owns — the
        // same set they may enroll into (mirrors StoreEnrollmentRequest).
        $batchIds = $this->user()->batchesCoordinated()->pluck('id')->all();

        return [
            'name' => ['required', 'string', 'max:150'],
            // Username is the login credential now — email is parked (not collected here).
            'username' => ['required', 'string', 'min:3', 'max:50', 'regex:/^[A-Za-z0-9._-]+$/', 'unique:users,username'],
            'password' => ['required', 'string', 'min:8'],
            // Coordinators may only create students or supervisors — never
            // another coordinator or an admin.
            'role' => ['required', Rule::in(['student', 'supervisor'])],
            // A student is pre-set a program + intended batch here; the real
            // enrollment is realized later when the coordinator ACCEPTS the
            // student's submitted info sheet.
            'program_id' => ['required_if:role,student', 'nullable', 'integer', 'exists:programs,id'],
            'batch_id' => ['required_if:role,student', 'nullable', 'integer', 'exists:batches,id', Rule::in($batchIds)],
            'student_id_number' => ['nullable', 'string', 'max:30', 'unique:users,student_id_number'],
        ];
    }

    public function messages(): array
    {
        return [
            'username.regex' => 'Username may only contain letters, numbers, dots, dashes and underscores.',
            'batch_id.in' => 'The selected batch is not one you coordinate.',
        ];
    }
}
