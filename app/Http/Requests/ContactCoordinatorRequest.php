<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ContactCoordinatorRequest extends FormRequest
{
    /**
     * Public on purpose: this is the one endpoint a visitor who has NO account
     * is expected to reach — someone who never received their credentials and
     * therefore cannot sign in to ask about them. Abuse is bounded by the
     * route's own throttle rather than by auth.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * The 20-character floor on the message is the only rule here that is not
     * merely structural. A one-word "help" gives the coordinator nothing to act
     * on and costs them a round trip to find out what was meant, so the form
     * asks for a sentence before it will send.
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'string', 'email:rfc', 'max:255'],
            'message' => ['required', 'string', 'min:20', 'max:2000'],
        ];
    }

    public function attributes(): array
    {
        return [
            'name' => 'name',
            'email' => 'email address',
            'message' => 'message',
        ];
    }

    public function messages(): array
    {
        return [
            'message.min' => 'Please describe the problem in at least 20 characters.',
        ];
    }
}
