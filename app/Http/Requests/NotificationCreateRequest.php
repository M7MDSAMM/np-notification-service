<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class NotificationCreateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'user_uuid'       => ['required', 'uuid'],
            'template_key'    => ['required', 'string', 'max:120'],
            'channels'        => ['required', 'array', 'min:1'],
            'channels.*'      => ['string', Rule::in(['email', 'whatsapp', 'push'])],
            'variables'       => ['required', 'array'],
            'idempotency_key' => ['nullable', 'string', 'max:100'],
        ];
    }
}
