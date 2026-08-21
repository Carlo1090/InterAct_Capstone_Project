<?php

namespace App\Http\Requests\Supervisor;

use Illuminate\Foundation\Http\FormRequest;

class AdjustDtrSessionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === 'supervisor';
    }

    /**
     * The correction surface for a forgotten clock-out or a wrong shift
     * length. A reason is REQUIRED — an adjusted timesheet with no stated
     * cause is exactly the record a compliance audit cannot accept.
     */
    public function rules(): array
    {
        return [
            'minutes_worked' => ['required', 'integer', 'min:0', 'max:1440'],
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return [
            'reason.required' => 'Give a reason for the adjustment — it is kept on the record.',
            'minutes_worked.max' => 'A single session cannot exceed 24 hours.',
        ];
    }
}
