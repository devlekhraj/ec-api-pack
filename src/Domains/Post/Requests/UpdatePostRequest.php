<?php

declare(strict_types=1);

namespace EcApi\Domains\Post\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePostRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $postId = (int) $this->route('id');

        return [
            'id' => ['required', 'integer', 'exists:posts,id'],
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'slug' => ['required', 'string', 'max:255', Rule::unique('posts', 'slug')->ignore($postId, 'id')],
            'sub_title' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'content' => ['sometimes', 'nullable', 'string'],
            'category_ids' => ['sometimes', 'array'],
            'category_ids.*' => ['integer', 'exists:categories,id'],
            'has_custom_author' => ['sometimes', 'boolean'],
            'custom_author_name' => [
                Rule::requiredIf(fn () => $this->boolean('has_custom_author')),
                'nullable',
                'string',
                'max:255',
            ],
            'author_id' => [
                Rule::requiredIf(fn () => ! $this->boolean('has_custom_author')),
                'nullable',
                'integer',
                'exists:users,id',
            ],
            'report_from' => ['sometimes', 'nullable', 'string', 'max:255'],
            'status' => ['sometimes', 'required', 'boolean'],
            'meta_title' => ['sometimes', 'nullable', 'string'],
            'meta_keyword' => ['sometimes', 'nullable', 'string'],
            'meta_description' => ['sometimes', 'nullable', 'string'],
        ];
    }
}
