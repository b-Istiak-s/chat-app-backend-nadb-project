<?php

namespace App\Http\Requests\Web\Auth;

use Illuminate\Foundation\Http\FormRequest;

class StartRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // `sometimes` lets the dashboard's "Subscribe via OTP"
            // button POST without a phone field — the controller
            // falls back to `Auth::user()->phone` for the
            // signed-in-but-not-subscribed path. The regex still
            // validates the format when the field IS supplied
            // (e.g. /login/start).
            'phone' => ['sometimes', 'string', 'regex:/^01[3-9][0-9]{8}$/'],
        ];
    }

    public function messages(): array
    {
        return [
            'phone.regex' => 'Please enter a valid 11-digit Bangladeshi phone number (e.g. 01812345678).',
        ];
    }
}
