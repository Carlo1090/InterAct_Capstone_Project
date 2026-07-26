<?php

namespace App\Http\Requests\Student;

use Illuminate\Foundation\Http\FormRequest;

class UpdateReminderPreferenceRequest extends FormRequest
{
    /**
     * Route-level middleware already restricts this to an enrolled student
     * acting on their own profile — there is no id in the payload to authorize.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'reminder_enabled' => ['required', 'boolean'],

            // null/omitted means "follow my batch's working days" rather than
            // "remind me on no days" — turning reminders off is what
            // reminder_enabled is for.
            'reminder_days' => ['nullable', 'array', 'max:7'],
            'reminder_days.*' => ['integer', 'between:1,7'],

            // Only the hour is honoured (the command runs hourly), but we accept
            // and store H:i so the UI can round-trip a normal time input.
            'reminder_time' => ['nullable', 'date_format:H:i'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'reminder_enabled' => 'reminder toggle',
            'reminder_days' => 'reminder days',
            'reminder_days.*' => 'reminder day',
            'reminder_time' => 'reminder time',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'reminder_days.*.between' => 'Each reminder day must be a day number from 1 (Monday) to 7 (Sunday).',
            'reminder_time.date_format' => 'The reminder time must be a 24-hour time such as 21:00.',
        ];
    }
}
