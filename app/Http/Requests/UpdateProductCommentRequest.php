<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateProductCommentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'content' => ['required', 'string', 'min:2', 'max:2000'],
        ];
    }

    public function messages(): array
    {
        return [
            'content.required' => 'Comment text cannot be empty.',
            'content.min' => 'Comment must contain at least 2 characters.',
            'content.max' => 'Comment cannot exceed 2000 characters.',
        ];
    }
}
