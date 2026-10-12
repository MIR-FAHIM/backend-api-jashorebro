<?php

namespace App\Http\Requests;

use App\Models\CommentReport;
use Illuminate\Foundation\Http\FormRequest;

class ReportCommentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'in:' . implode(',', CommentReport::REASONS)],
            'description' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return [
            'reason.required' => 'A report reason is required.',
            'reason.in' => 'Invalid report reason. Supported: spam, abuse, harassment, misleading, off_topic, other.',
            'description.max' => 'Description cannot exceed 1000 characters.',
        ];
    }
}
