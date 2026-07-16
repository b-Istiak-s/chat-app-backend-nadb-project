<?php

namespace App\Http\Requests\Api\Auth;

use App\Traits\ApiResponses\ApiResponseTrait;
use Illuminate\Foundation\Http\FormRequest;

class StartRequest extends FormRequest
{
    use ApiResponseTrait;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'phone' => ['required', 'string', 'regex:/^01[3-9][0-9]{8}$/'],
        ];
    }

    protected function failedValidation(\Illuminate\Contracts\Validation\Validator $validator)
    {
        $this->sendValidationErrorResponse($validator);
    }
}
