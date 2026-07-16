<?php

namespace App\Http\Requests\Api\Chat;

use App\Traits\ApiResponses\ApiResponseTrait;
use Illuminate\Foundation\Http\FormRequest;

class StreamMessageRequest extends FormRequest
{
    use ApiResponseTrait;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'message' => ['required', 'string', 'min:1', 'max:8000'],
        ];
    }

    protected function failedValidation(\Illuminate\Contracts\Validation\Validator $validator)
    {
        $this->sendValidationErrorResponse($validator);
    }
}