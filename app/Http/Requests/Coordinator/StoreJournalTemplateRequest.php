<?php

namespace App\Http\Requests\Coordinator;

use App\Http\Requests\Coordinator\Concerns\ValidatesJournalTemplate;
use Illuminate\Foundation\Http\FormRequest;

class StoreJournalTemplateRequest extends FormRequest
{
    use ValidatesJournalTemplate;

    public function authorize(): bool
    {
        return $this->user()?->role === 'coordinator';
    }

    public function rules(): array
    {
        return $this->journalTemplateRules();
    }
}
