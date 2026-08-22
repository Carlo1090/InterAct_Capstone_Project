<?php

namespace App\Http\Requests\Supervisor;

use Illuminate\Foundation\Http\FormRequest;

class StoreGeofenceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === 'supervisor';
    }

    public function rules(): array
    {
        return [
            'company_id' => ['required', 'integer', 'exists:companies,id'],
            'label' => ['required', 'string', 'max:150'],
            // Captured from the supervisor's own device while standing at the
            // workplace — this is what anchors the fence.
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            // 150m default. Deliberately generous: GPS inside a concrete
            // building is poor, and a fence too tight rejects interns who are
            // genuinely present, which is the worse failure.
            'radius_meters' => ['nullable', 'integer', 'min:25', 'max:2000'],
            'captured_accuracy' => ['nullable', 'integer', 'min:0', 'max:65535'],
        ];
    }

    public function messages(): array
    {
        return [
            'latitude.required' => 'Turn on location access so the QR code can be anchored to this workplace.',
            'longitude.required' => 'Turn on location access so the QR code can be anchored to this workplace.',
        ];
    }
}
