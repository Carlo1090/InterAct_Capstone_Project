<?php

namespace App\Support\ExitInterview;

use App\Models\Batch;
use App\Models\BatchStudent;
use App\Models\Department;
use App\Models\StudentExitInterview;
use App\Support\ExitInterview\Forms\CabmForm;
use App\Support\ExitInterview\Forms\CastForm;

/**
 * The catalogue of hardcoded exit interview forms, and the one place that
 * answers "which form does this student fill in?".
 *
 * A department is assigned one of these by the admin
 * (departments.exit_interview_form, chosen when the department is created and
 * changeable on edit); a student's form is the one their BATCH's program's
 * department carries. An interview snapshots the key it was answered under
 * (student_exit_interviews.form_key) so a finished form keeps rendering under
 * its own questions even if the department's assignment later changes — the
 * same reason a reviewed daily entry is labelled by its log's own template
 * rather than the student's current enrolment.
 *
 * Adding a department's form is one new class under Forms/ and one entry in
 * all(). Nothing else in the app names a form key.
 */
final class ExitInterviewForms
{
    /** The original form, and what everything falls back to. */
    public const DEFAULT = 'cabm';

    /** @var array<string, ExitInterviewForm>|null */
    private static ?array $forms = null;

    /**
     * @return array<string, ExitInterviewForm> keyed by form key, in display order
     */
    public static function all(): array
    {
        if (self::$forms !== null) {
            return self::$forms;
        }

        $forms = [];

        foreach ([CabmForm::definition(), CastForm::definition()] as $form) {
            $forms[$form->key] = $form;
        }

        return self::$forms = $forms;
    }

    /**
     * @return array<int, string>
     */
    public static function keys(): array
    {
        return array_keys(self::all());
    }

    public static function has(?string $key): bool
    {
        return $key !== null && isset(self::all()[$key]);
    }

    /**
     * An unknown or missing key resolves to the DEFAULT rather than throwing:
     * a form removed from the catalogue must not make an old interview
     * undownloadable.
     */
    public static function get(?string $key): ExitInterviewForm
    {
        return self::all()[$key] ?? self::all()[self::DEFAULT];
    }

    public static function forDepartment(?Department $department): ExitInterviewForm
    {
        return self::get($department?->exit_interview_form);
    }

    public static function forBatch(?Batch $batch): ExitInterviewForm
    {
        $batch?->loadMissing('program.department');

        return self::forDepartment($batch?->program?->department);
    }

    /**
     * The form an interview was (or will be) answered under: its own snapshot
     * once one exists, otherwise its batch's department's current choice.
     */
    public static function forInterview(?StudentExitInterview $interview, ?BatchStudent $enrollment = null): ExitInterviewForm
    {
        if ($interview?->form_key !== null) {
            return self::get($interview->form_key);
        }

        return self::forBatch($enrollment?->batch ?? $interview?->batch);
    }
}
