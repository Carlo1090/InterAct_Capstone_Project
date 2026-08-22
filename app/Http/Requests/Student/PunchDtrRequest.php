<?php

namespace App\Http\Requests\Student;

use Illuminate\Foundation\Http\FormRequest;

class PunchDtrRequest extends FormRequest
{
    /** time_in_accuracy / time_out_accuracy are unsignedSmallInteger columns. */
    private const ACCURACY_CEILING = 65535;

    public function authorize(): bool
    {
        return $this->user()?->role === 'student';
    }

    /**
     * Clamp the reported accuracy instead of rejecting it.
     *
     * GeolocationPosition.coords.accuracy is unbounded in the spec, and a
     * phone with no GPS lock indoors can genuinely report a six-figure radius.
     * A max: rule would then 422 the whole punch — refusing to clock in a
     * student who is standing exactly where they should be, over a diagnostic
     * field that gates nothing. The distance check is the gate; this number is
     * only recorded for the supervisor's audit trail, so saturating it at the
     * column ceiling loses nothing that matters.
     */
    protected function prepareForValidation(): void
    {
        $accuracy = $this->input('accuracy');

        if (is_numeric($accuracy)) {
            $this->merge([
                'accuracy' => (int) min(self::ACCURACY_CEILING, max(0, round((float) $accuracy))),
            ]);
        }
    }

    public function rules(): array
    {
        return [
            'site_token' => ['required', 'string', 'size:32'],
            // Accepted and ignored while every geofence is static. Declared
            // now so switching rotation on later needs no request change and
            // no client change — see DtrService::verifyRotatingCode().
            'code' => ['nullable', 'string', 'max:64'],
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            // GeolocationPosition.coords.accuracy, in metres. Recorded for
            // the supervisor's audit trail rather than used as a gate: a
            // vague fix that still lands inside the radius is accepted.
            // Already clamped to the column ceiling in prepareForValidation().
            'accuracy' => ['nullable', 'integer', 'min:0', 'max:'.self::ACCURACY_CEILING],
        ];
    }

    public function messages(): array
    {
        return [
            'latitude.required' => 'Your location could not be read. Allow location access and try again.',
            'longitude.required' => 'Your location could not be read. Allow location access and try again.',
        ];
    }
}
