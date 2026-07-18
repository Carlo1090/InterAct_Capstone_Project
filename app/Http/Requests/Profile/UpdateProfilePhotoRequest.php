<?php

namespace App\Http\Requests\Profile;

use App\Services\AvatarProcessingService;
use Illuminate\Contracts\Validation\Validator as ValidatorContract;
use Illuminate\Foundation\Http\FormRequest;

class UpdateProfilePhotoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'photo' => ['required', 'image', 'mimes:jpeg,jpg,png,webp', 'max:2048'],
        ];
    }

    /**
     * The mimes/image rules above trust the file's extension and declared
     * Content-Type. This adds a real magic-byte check (via
     * AvatarProcessingService::sniffType(), which reads the actual image
     * header) so a renamed non-image file can't slip through as a "valid"
     * upload just because it claims a JPEG/PNG/WebP extension or MIME type.
     */
    public function withValidator(ValidatorContract $validator): void
    {
        $validator->after(function (ValidatorContract $validator) {
            if ($validator->errors()->has('photo')) {
                return;
            }

            $file = $this->file('photo');

            if (! $file || app(AvatarProcessingService::class)->sniffType($file->get()) === null) {
                $validator->errors()->add('photo', 'The photo does not appear to be a genuine JPEG, PNG, or WebP image.');
            }
        });
    }
}
