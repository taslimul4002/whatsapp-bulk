<?php

namespace App\Http\Requests;

use App\Models\WhatsAppAccount;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class SaveWhatsAppTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() ?? false;
    }

    public function rules(): array
    {
        $account = WhatsAppAccount::find(1);

        return [
            'name' => ['required', 'string', 'max:128', 'regex:/^[a-z0-9_]+$/', Rule::unique('whats_app_templates')->where('business_account_id', $account?->business_account_id)->where('language', $this->input('language'))->ignore($this->route('template'))],
            'language' => ['required', 'string', 'max:32', 'regex:/^[a-zA-Z]{2,3}([_-][a-zA-Z0-9]{2,8})?$/'],
            'category' => ['required', Rule::in(['MARKETING', 'UTILITY'])],
            'header_format' => ['required', Rule::in(['NONE', 'TEXT', 'IMAGE', 'VIDEO', 'DOCUMENT'])],
            'header_text' => ['nullable', 'string', 'max:60', 'required_if:header_format,TEXT'],
            'header_example' => ['nullable', 'string', 'max:4096'],
            'body' => ['required', 'string', 'max:1024'],
            'body_examples' => ['present', 'array', 'max:20'],
            'body_examples.*' => ['required', 'string', 'max:255'],
            'footer' => ['nullable', 'string', 'max:60'],
            'buttons' => ['present', 'array', 'max:10'],
            'buttons.*' => ['required', 'array:type,text,value'],
            'buttons.*.type' => ['required', Rule::in(['QUICK_REPLY', 'URL', 'PHONE_NUMBER'])],
            'buttons.*.text' => ['required', 'string', 'max:25'],
            'buttons.*.value' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $body = $this->input('body');
            preg_match_all('/\{\{([0-9]+)\}\}/', $body, $matches);
            $numbers = array_values(array_unique(array_map('intval', $matches[1])));
            sort($numbers);
            if (($numbers && $numbers !== range(1, count($numbers))) || count($numbers) !== count($this->input('body_examples'))) {
                $validator->errors()->add('body_examples', 'templates.invalid_examples');
            }
            if (str_contains(preg_replace('/\{\{[0-9]+\}\}/', '', $body), '{{')) {
                $validator->errors()->add('body', 'templates.invalid_examples');
            }
            $format = $this->input('header_format');
            $header = $this->input('header_text') ?? '';
            $example = $this->input('header_example');
            if (($format === 'TEXT' && str_contains($header, '{{') && (substr_count($header, '{{1}}') !== 1 || str_contains(str_replace('{{1}}', '', $header), '{{') || ! $example))
                || (in_array($format, ['IMAGE', 'VIDEO', 'DOCUMENT'], true) && ! $example)) {
                $validator->errors()->add('header_example', 'templates.invalid_header');
            }
            foreach ($this->input('buttons') as $index => $button) {
                $value = $button['value'] ?? '';
                if ($button['type'] === 'URL' && (! filter_var($value, FILTER_VALIDATE_URL) || ! preg_match('#^https?://#i', $value) || str_contains($value, '{{'))) {
                    $validator->errors()->add("buttons.$index.value", 'templates.invalid_url');
                }
                if ($button['type'] === 'PHONE_NUMBER' && ! preg_match('/^\+[0-9]{7,15}$/', $value)) {
                    $validator->errors()->add("buttons.$index.value", 'templates.invalid_phone');
                }
            }
        }];
    }

    public function components(): array
    {
        $data = $this->validated();
        $components = [];
        if ($data['header_format'] === 'TEXT') {
            $header = ['type' => 'HEADER', 'format' => 'TEXT', 'text' => $data['header_text']];
            if (str_contains($data['header_text'], '{{1}}')) {
                $header['example'] = ['header_text' => [$data['header_example']]];
            }
            $components[] = $header;
        } elseif ($data['header_format'] !== 'NONE') {
            $components[] = ['type' => 'HEADER', 'format' => $data['header_format'], 'example' => ['header_handle' => [$data['header_example']]]];
        }
        $body = ['type' => 'BODY', 'text' => $data['body']];
        if ($data['body_examples']) {
            $body['example'] = ['body_text' => [$data['body_examples']]];
        }
        $components[] = $body;
        if (! empty($data['footer'])) {
            $components[] = ['type' => 'FOOTER', 'text' => $data['footer']];
        }
        if ($data['buttons']) {
            $buttons = [];
            foreach ($data['buttons'] as $button) {
                $item = ['type' => $button['type'], 'text' => $button['text']];
                if ($button['type'] === 'URL') {
                    $item['url'] = $button['value'];
                } elseif ($button['type'] === 'PHONE_NUMBER') {
                    $item['phone_number'] = $button['value'];
                }
                $buttons[] = $item;
            }
            $components[] = ['type' => 'BUTTONS', 'buttons' => $buttons];
        }

        return $components;
    }
}
