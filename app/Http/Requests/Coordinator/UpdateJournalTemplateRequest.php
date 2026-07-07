<?php

namespace App\Http\Requests\Coordinator;

use App\Http\Requests\Coordinator\Concerns\ValidatesJournalTemplate;
use Illuminate\Foundation\Http\FormRequest;

class UpdateJournalTemplateRequest extends FormRequest
{
    use ValidatesJournalTemplate;

    public function authorize(): bool
    {
        if ($this->user()?->role !== 'coordinator') {
            return false;
        }

        $journalTemplate = $this->route('journalTemplate');

        return $journalTemplate && in_array($journalTemplate->program_id, $this->coordinatorProgramIds(), true);
    }

    public function rules(): array
    {
        return $this->journalTemplateRules();
    }
}
