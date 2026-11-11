<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveContactRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['required', 'string', 'regex:/^\+[1-9][0-9]{6,14}$/', Rule::unique('contacts')->where('workspace_id', $this->route('workspace')->id)->ignore($this->route('contact'))],
            'email' => ['nullable', 'email', 'max:255'],
            'opted_in' => ['required', 'boolean'],
            'groups' => ['present', 'array'],
            'groups.*' => ['integer', 'distinct', Rule::exists('contact_groups', 'id')->where('workspace_id', $this->route('workspace')->id)],
            'tags' => ['present', 'array'],
            'tags.*' => ['integer', 'distinct', Rule::exists('contact_tags', 'id')->where('workspace_id', $this->route('workspace')->id)],
        ];
    }
}
