<?php

namespace App\Http\Requests;

use App\Support\Permissions;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class SaveUserRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users')->ignore($this->route('user'))],
            'password' => [$this->isMethod('post') ? 'required' : 'nullable', 'confirmed', Password::defaults()],
            'type' => ['required', Rule::in(['admin', 'user'])],
            'permissions' => ['present', 'array'],
            'permissions.*' => ['distinct', Rule::in(Permissions::All)],
            'workspaces' => ['present', 'array'],
            'workspaces.*' => ['integer', 'distinct', Rule::exists('workspaces', 'id')],
        ];
    }
}
