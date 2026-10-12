<?php

namespace App\Http\Requests;

use App\Models\CommentReaction;
use Illuminate\Foundation\Http\FormRequest;

class ToggleReactionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'reaction_type' => ['required', 'string', 'in:' . implode(',', CommentReaction::TYPES)],
        ];
    }

    public function messages(): array
    {
        return [
            'reaction_type.required' => 'A valid reaction type is required.',
            'reaction_type.in' => 'Invalid reaction type. Supported: like, helpful, agree, need_details, good_deal.',
        ];
    }
}
