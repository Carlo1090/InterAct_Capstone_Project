<?php

namespace App\Http\Requests\Student;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreWeeklyLogRequest extends FormRequest
{
    /**
     * The weekly narrative has its own fixed character limit, separate from
     * (and larger than) the daily journal's — it's a compiled/edited summary
     * of a full Mon-Fri week, not a single day's entry.
     */
    public const CHAR_LIMIT = 5000;

    public function authorize(): bool
    {
        return $this->user()?->role === 'student';
    }

    public function rules(): array
    {
        return [
            'week_start' => ['required', 'date'],
            'narrative' => ['nullable', 'string'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $length = mb_strlen((string) $this->input('narrative', ''));

            if ($length > self::CHAR_LIMIT) {
                $validator->errors()->add(
                    'narrative',
                    'This narrative exceeds the '.self::CHAR_LIMIT.'-character limit ('.$length.' characters).'
                );
            }
        });
    }
}
