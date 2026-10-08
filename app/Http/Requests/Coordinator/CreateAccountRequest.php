<?php

namespace App\Http\Requests\Coordinator;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class CreateAccountRequest extends FormRequest
{
    /** users.name is a VARCHAR(150) and holds the three name parts joined. */
    public const MAX_FULL_NAME = 150;

    public function authorize(): bool
    {
        return $this->user()?->role === 'coordinator';
    }

    public function rules(): array
    {
        // A student's intended batch may be any batch in the coordinator's
        // department — the same set they may enroll into (mirrors
        // StoreEnrollmentRequest and bulk import).
        $batchIds = $this->user()->placeableBatchIds()->all();

        return [
            'first_name' => ['required', 'string', 'max:100'],
            'middle_name' => ['nullable', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            // Username is the login credential now — email is parked (not collected here).
            'username' => ['nullable', 'string', 'min:3', 'max:50', 'regex:/^[A-Za-z0-9._-]+$/', 'unique:users,username'],
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
            'batch_id.in' => 'The selected batch is outside your department.',
        ];
    }

    /**
     * The three parts are each capped at 100 (they mirror the info sheet's own
     * fields), but they are stored JOINED in users.name, which holds 150. Three
     * legal parts could therefore add up to a name the column cannot take:
     * SQLite accepts it silently, and MySQL's strict mode answered with a 500
     * instead of a message the coordinator could act on.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $full = collect([$this->input('first_name'), $this->input('middle_name'), $this->input('last_name')])
                ->map(fn ($part) => trim((string) $part))
                ->filter()
                ->implode(' ');

            if (mb_strlen($full) > self::MAX_FULL_NAME) {
                $validator->errors()->add('last_name', 'The full name may not be longer than '.self::MAX_FULL_NAME.' characters altogether.');
            }
        });
    }
}
