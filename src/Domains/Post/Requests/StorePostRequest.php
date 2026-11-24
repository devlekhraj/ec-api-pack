<?php

declare(strict_types=1);

namespace EcApi\Domains\Post\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StorePostRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255', 'unique:posts,title'],
            'slug' => ['required', 'string', 'max:255', 'unique:posts,slug'],
            'status' => ['nullable', 'boolean'],
        ];
    }
}
