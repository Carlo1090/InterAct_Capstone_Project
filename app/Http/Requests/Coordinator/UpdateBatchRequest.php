<?php

namespace App\Http\Requests\Coordinator;

use App\Models\Batch;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateBatchRequest extends FormRequest
{
    public function authorize(): bool
    {
        if ($this->user()?->role !== 'coordinator') {
            return false;
        }

        $batch = $this->route('batch');

        return $batch && $batch->coordinator_id === $this->user()->id;
    }

    public function rules(): array
    {
        $batch = $this->route('batch');

        return [
            'name' => ['sometimes', 'string', 'max:150'],
            'academic_year' => ['sometimes', 'string', 'max:20'],
            'semester' => ['sometimes', 'string', 'max:30'],
            'start_date' => ['sometimes', 'date'],
            'end_date' => ['sometimes', 'date', 'after:start_date'],
            'required_hours' => ['sometimes', 'integer', 'min:1'],
            'working_days_per_week' => ['sometimes', 'integer', 'between:1,7'],
            'daily_reminder_time' => ['sometimes', 'date_format:H:i'],
            'is_active' => ['sometimes', 'boolean'],
            'ojt_type' => ['sometimes', Rule::in(Batch::OJT_TYPES)],
            'journal_template_id' => [
                'sometimes',
                'nullable',
                'integer',
                // The chosen template must cover the batch's program (via the pivot).
                Rule::exists('journal_template_program', 'journal_template_id')->where('program_id', $batch?->program_id),
            ],
        ];
    }

    /**
     * The OJT type is settled while the roster is empty and frozen the moment
     * the first intern is enrolled.
     *
     * This is a real data rule, not a UI nicety, which is why it is enforced
     * here rather than only by disabling the control. Flipping a live cohort
     * would hand every journal already waiting on one reviewer to a different
     * one mid-placement — and on a supervisor-supported batch it would strand
     * enrollments that pin a supervisor_id the new mode says should not exist.
     * Re-stating the SAME value is always allowed, so a client that PUTs the
     * whole form back (which the batches page does) is never refused for a
     * field it did not touch.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if (! $this->has('ojt_type')) {
                return;
            }

            $batch = $this->route('batch');

            if (! $batch || $this->input('ojt_type') === $batch->ojt_type) {
                return;
            }

            if ($batch->batchStudents()->exists()) {
                $validator->errors()->add(
                    'ojt_type',
                    'This batch already has interns enrolled, so its OJT type can no longer be changed. Create a new batch instead.'
                );
            }
        });
    }
}
