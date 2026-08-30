<?php

namespace App\Http\Requests\Student;

use Illuminate\Foundation\Http\FormRequest;

class UpdateWeeklyActivityEntryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === 'student';
    }

    /**
     * Nullable throughout, matching StoreWeeklyActivityEntryRequest: the grid
     * auto-saves mid-edit, so a student clearing a cell must not have the whole
     * row's save rejected. Emptying every column is allowed — Delete is the
     * tidier way to drop a row, but a blank row is exactly what the pre-printed
     * paper form has, so it is not an error state.
     */
    public function rules(): array
    {
        return [
            'inclusive_date_start' => ['sometimes', 'nullable', 'date'],
            // Only compare the two dates when there is a start date to compare
            // against. `after_or_equal:<field>` falls back to Carbon::parse(null)
            // — i.e. NOW — when the referenced field is absent, which would
            // silently reject any end date in the past.
            'inclusive_date_end' => array_values(array_filter([
                'sometimes', 'nullable', 'date',
                $this->filled('inclusive_date_start') ? 'after_or_equal:inclusive_date_start' : null,
            ])),
            'activities' => ['sometimes', 'nullable', 'string'],
            'documents_records' => ['sometimes', 'nullable', 'string'],
            'objectives' => ['sometimes', 'nullable', 'string'],
            'supervisor_name' => ['sometimes', 'nullable', 'string', 'max:150'],
            'supervisor_position' => ['sometimes', 'nullable', 'string', 'max:100'],
        ];
    }
}
