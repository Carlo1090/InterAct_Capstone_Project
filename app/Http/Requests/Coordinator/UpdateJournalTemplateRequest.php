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

        // In scope when the template covers at least one of the coordinator's programs.
        return $journalTemplate
            && $journalTemplate->programs()->whereIn('programs.id', $this->coordinatorProgramIds())->exists();
    }

    public function rules(): array
    {
        return $this->journalTemplateRules();
    }
}
