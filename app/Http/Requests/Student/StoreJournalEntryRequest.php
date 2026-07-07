<?php

namespace App\Http\Requests\Student;

use Illuminate\Foundation\Http\FormRequest;

class StoreJournalEntryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === 'student';
    }

    public function rules(): array
    {
        return [
            'entry_date' => ['required', 'date'],
            'status' => ['required', 'in:draft,submitted'],
            'content' => ['required', 'array'],
        ];
    }
}
