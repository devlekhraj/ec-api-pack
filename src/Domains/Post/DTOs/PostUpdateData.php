<?php

declare(strict_types=1);

namespace EcApi\Domains\Post\DTOs;

final class PostUpdateData
{
    public function __construct(
        public readonly int|string $id,
        public readonly string $title,
        public readonly string $slug,
        public readonly ?string $sub_title,
        public readonly ?string $content,
        /** @var array<int, int|string> */
        public readonly array $category_ids,
        public readonly int|string|null $author_id,
        public readonly bool $has_custom_author,
        public readonly ?string $custom_author_name,
        public readonly ?string $report_from,
        public readonly ?bool $status,
        public readonly ?string $meta_title,
        public readonly ?string $meta_keyword,
        public readonly ?string $meta_description,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            id: $data['id'],
            title: (string) ($data['title'] ?? ''),
            slug: (string) ($data['slug'] ?? ''),
            sub_title: isset($data['sub_title']) ? (string) $data['sub_title'] : null,
            content: isset($data['content']) ? (string) $data['content'] : null,
            category_ids: array_values(array_map(
                static fn ($value) => is_numeric($value) ? (int) $value : $value,
                $data['category_ids'] ?? [],
            )),
            author_id: $data['author_id'] ?? null,
            has_custom_author: (bool) ($data['has_custom_author'] ?? false),
            custom_author_name: isset($data['custom_author_name']) ? (string) $data['custom_author_name'] : null,
            report_from: isset($data['report_from']) ? (string) $data['report_from'] : null,
            status: array_key_exists('status', $data) ? (bool) $data['status'] : null,
            meta_title: isset($data['meta_title']) ? (string) $data['meta_title'] : null,
            meta_keyword: isset($data['meta_keyword']) ? (string) $data['meta_keyword'] : null,
            meta_description: isset($data['meta_description']) ? (string) $data['meta_description'] : null,
        );
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'slug' => $this->slug,
            'sub_title' => $this->sub_title,
            'content' => $this->content,
            'category_ids' => $this->category_ids,
            'author_id' => $this->author_id,
            'has_custom_author' => $this->has_custom_author,
            'custom_author_name' => $this->custom_author_name,
            'report_from' => $this->report_from,
            'status' => $this->status,
            'meta_title' => $this->meta_title,
            'meta_keyword' => $this->meta_keyword,
            'meta_description' => $this->meta_description,
        ];
    }
}
