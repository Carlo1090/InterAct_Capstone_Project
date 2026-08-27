<?php

namespace App\Http\Requests\Student;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class StoreWeeklyActivityEntryRequest extends FormRequest
{
    /**
     * The five printed columns plus the two signatory fields. A row must carry
     * something in at least one of them to be worth a database row.
     */
    public const CONTENT_FIELDS = [
        'inclusive_date_start',
        'inclusive_date_end',
        'activities',
        'documents_records',
        'objectives',
        'supervisor_name',
        'supervisor_position',
    ];

    public function authorize(): bool
    {
        return $this->user()?->role === 'student';
    }

    /**
     * Every field is nullable on purpose. The grid auto-saves while the student
     * is still typing, so requiring the dates and Activities up front meant a
     * half-filled row could not be stored at all and was lost on logout. The
     * "not completely blank" rule below replaces that guard — it refuses an
     * empty row without refusing an unfinished one.
     */
    public function rules(): array
    {
        return [
            'inclusive_date_start' => ['nullable', 'date'],
            // Only compare the two dates when there is a start date to compare
            // against. `after_or_equal:<field>` falls back to Carbon::parse(null)
            // — i.e. NOW — when the referenced field is absent, which would
            // silently reject any end date in the past.
            'inclusive_date_end' => array_values(array_filter([
                'nullable', 'date',
                $this->filled('inclusive_date_start') ? 'after_or_equal:inclusive_date_start' : null,
            ])),
            'activities' => ['nullable', 'string'],
            'documents_records' => ['nullable', 'string'],
            'objectives' => ['nullable', 'string'],
            'supervisor_name' => ['nullable', 'string', 'max:150'],
            'supervisor_position' => ['nullable', 'string', 'max:100'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $hasContent = collect(self::CONTENT_FIELDS)
                ->contains(fn (string $field) => trim((string) $this->input($field)) !== '');

            if (! $hasContent) {
                $validator->errors()->add('activities', 'Fill in at least one column before this row can be saved.');
            }
        });
    }
}
