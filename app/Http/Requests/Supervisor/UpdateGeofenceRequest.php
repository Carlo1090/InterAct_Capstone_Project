<?php

namespace App\Http\Requests\Supervisor;

use Illuminate\Foundation\Http\FormRequest;

class UpdateGeofenceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === 'supervisor';
    }

    /**
     * Label, radius and active state only. The coordinates are deliberately
     * NOT editable here: re-anchoring a fence means standing at the workplace
     * again, which is what creating a new site does. Silently editable
     * coordinates would let a supervisor move an existing fence to their
     * house without any of the capture evidence being refreshed.
     */
    public function rules(): array
    {
        return [
            'label' => ['sometimes', 'required', 'string', 'max:150'],
            'radius_meters' => ['sometimes', 'required', 'integer', 'min:25', 'max:2000'],
            'is_active' => ['sometimes', 'required', 'boolean'],
        ];
    }
}
