<?php

namespace App\Http\Requests\Web\Auth;

use Illuminate\Foundation\Http\FormRequest;

class VerifyOtpRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'phone' => ['required', 'string', 'regex:/^01[3-9][0-9]{8}$/'],
            'otp' => ['required', 'string', 'regex:/^[0-9]{4,6}$/'],
        ];
    }

    public function messages(): array
    {
        return [
            'phone.regex' => 'Please enter a valid 11-digit Bangladeshi phone number.',
            'otp.regex' => 'OTP must be 4–6 digits.',
        ];
    }
}
