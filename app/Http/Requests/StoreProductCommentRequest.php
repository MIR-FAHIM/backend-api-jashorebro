<?php

namespace App\Http\Requests;

use App\Models\ProductComment;
use Illuminate\Foundation\Http\FormRequest;

class StoreProductCommentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'content' => ['required', 'string', 'min:2', 'max:2000'],
            'comment_type' => ['nullable', 'string', 'in:' . implode(',', ProductComment::TYPES)],
            'parent_id' => ['nullable', 'integer', 'exists:product_comments,id'],
            'alternative_product_id' => ['nullable', 'integer', 'exists:products,id'],
            'price_suggestion' => ['nullable', 'numeric', 'min:0'],
            'attachment' => ['nullable', 'image', 'mimes:jpeg,png,webp,jpg', 'max:5120'],
            'attachment_url' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return [
            'content.required' => 'Comment text cannot be empty.',
            'content.min' => 'Comment must contain at least 2 characters.',
            'content.max' => 'Comment cannot exceed 2000 characters.',
            'attachment.max' => 'Attached image cannot exceed 5MB.',
        ];
    }
}
