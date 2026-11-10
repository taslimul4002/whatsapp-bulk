<?php

namespace App\Http\Requests;

use App\Support\Locales;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class SaveTranslationRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'key' => $this->isMethod('post')
                ? ['required', 'string', 'max:150', 'regex:/^[a-z0-9]+(?:[._-][a-z0-9]+)*$/']
                : ['prohibited'],
            Locales::English => ['required', 'string', 'max:5000'],
            Locales::Arabic => ['required', 'string', 'max:5000'],
        ];
    }
}
