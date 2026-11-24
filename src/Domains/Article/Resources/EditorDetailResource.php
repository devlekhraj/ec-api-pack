<?php

declare(strict_types=1);

namespace EcApi\Domains\Article\Resources;

use EcApi\Domains\Shared\Helpers\CustomHelper;
use EcApi\Models\ArticleVersion;
use EcApi\Models\ContentSection;
use EcApi\Models\GalleryUsage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ArticleVersion */
final class EditorDetailResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'article_id' => $this->article_id,
            'version_type' => $this->version_type,
            'status' => $this->status,
            'title' => $this->title,
            'sub_title' => $this->sub_title,
            'slug' => $this->slug,
            'report_from' => $this->report_from,
            'is_exclusive' => (bool) ($this->meta['is_exclusive'] ?? false),
            'is_featured' => (bool) ($this->meta['is_featured'] ?? false),
            'is_breaking' => (bool) ($this->meta['is_breaking'] ?? false),
            'tags' => $this->meta['tags'] ?? [],
            'has_other_category' => (bool) ($this->meta['has_other_category'] ?? false),
            'submitted_at' => $this->submitted_at?->toISOString(),
            'created_by' => $this->createdBy ? [
                'id' => $this->createdBy->id,
                'name' => $this->createdBy->name,
            ] : null,
            'authors' => $this->authors->map(function($author) {
                return [
                    'id' => $author->id,
                    'name' => $author->name,
                    'slug' => $author->slug,
                ];
            }),
            'author_ids' => $this->authors->pluck('id')->values()->all(),
            'category_ids' => $this->categories->pluck('id')->values()->all(),
            'categories' => $this->categories->map(static fn($c): array => [
                'id' => $c->id,
                'name' => $c->name,
                'slug' => $c->slug,
            ])->values()->all(),
            'sections' => $this->sections->map(static fn($s): array => self::sectionPayload($s))->values()->all(),
            'files' => $this->files->map(static function ($file): array {
                return [
                    'id' => $file->id,
                    'type' => $file->mime_type,
                    'filename' => $file->filename,
                    'url' => $file?->url,
                    'size' => CustomHelper::humanFileSize((int) $file->size),
                ];
            })->values()->all(),
            'banner_image' => $this->bannerImage ? [
                'id' => $this->bannerImage->id,
                'gallery_id' => $this->bannerImage->gallery_id,
                'url' => $this->bannerImage->gallery?->url ?: $this->bannerImage?->url,
                'alt_text' => $this->bannerImage->alt_text,
                'photo_credit' => $this->bannerImage->photo_credit,
                'caption' => $this->bannerImage->caption,
            ] : null,
            'seo' => $this->seo ? [
                'meta_title' => $this->seo->meta_title,
                'meta_description' => $this->seo->meta_descriptions,
                'focus_keywords' => $this->seo->focus_keywords ?? null,
                'json_ld' => $this->seo->json_ld,
                'canonical_url' => $this->seo->canonical_url,
                'og' => [
                    'title' => $this->seo->og_title,
                    'description' => $this->seo->og_description,
                    'type' => 'article',
                    'url' => $this->seo->canonical_url,
                    'image' => [
                        'url' => $this->seo->og_image_url ?: $this->bannerImage?->url,
                    ],
                ],
                'twitter' => [
                    'title' => $this->seo->twitter_title ?: $this->seo->og_title,
                    'description' => $this->seo->twitter_description ?: $this->seo->og_description,
                    'image' => [
                        'url' => $this->seo->twitter_image_url ?: $this->bannerImage?->url,
                    ],
                ],
                'ai_optimized' => $this->seo->ai_optimized,
                'ai_request_count' => $this->seo->ai_request_count,
                'finalized' => $this->seo->finalized,
                'locked' => $this->seo->locked,
                'seo_extra' => $this->seo->seo_extra,
            ] : null,
        ];
    }

    private function parseKeywords(?string $keywords): array
    {
        if (! $keywords) {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode(',', $keywords))));
    }

    public static function sectionPayload(ContentSection $section): array
    {
        $payload = $section->payload ?? [];
        $usages = $section->galleryUsages;
        $image = null;
        $images = [];

        if ($usages->isNotEmpty()) {
            if ($section->block_type === ContentSection::BLOCK_TYPE_IMAGE && (($payload['mode'] ?? 'single') !== 'grid')) {
                /** @var GalleryUsage|null $usage */
                $usage = $usages->first();

                if ($usage && $usage->gallery) {
                    $image = [
                        'gallery_usage_id' => $usage->id,
                        'gallery_id' => $usage->gallery_id,
                        'url' => $usage->gallery->url,
                        'alt_text' => $usage->alt_text,
                        'caption' => $usage->caption,
                        'photo_credit' => $usage->photo_credit,
                        'title' => $usage->title,
                    ];

                    $payload = array_merge($payload, [
                        'gallery_id' => $usage->gallery_id,
                        'src' => $usage->gallery->url,
                        'alt' => $usage->alt_text,
                        'caption' => $usage->caption,
                        'credit' => $usage->photo_credit,
                        'title' => $usage->title,
                        'link' => $usage->custom_properties['link'] ?? ($payload['link'] ?? null),
                    ]);
                }
            } elseif (($section->block_type === ContentSection::BLOCK_TYPE_IMAGE && (($payload['mode'] ?? 'single') === 'grid')) || $section->block_type === 'multi_image') {
                $images = $usages
                    ->filter(static fn(GalleryUsage $usage): bool => $usage->gallery !== null)
                    ->map(static fn(GalleryUsage $usage): array => [
                        'gallery_usage_id' => $usage->id,
                        'gallery_id' => $usage->gallery_id,
                        'url' => $usage->gallery?->url,
                        'src' => $usage->gallery?->url,
                        'alt_text' => $usage->alt_text,
                        'alt' => $usage->alt_text,
                        'caption' => $usage->caption,
                        'photo_credit' => $usage->photo_credit,
                        'seq_no' => $usage->seq_no,
                    ])
                    ->values()
                    ->all();

                $payload['images'] = array_map(static fn(array $image): array => [
                    'gallery_id' => $image['gallery_id'],
                    'src' => $image['src'],
                    'alt' => $image['alt'],
                ], $images);

                /** @var GalleryUsage|null $firstUsage */
                $firstUsage = $usages->first();

                if ($firstUsage) {
                    $payload['caption'] = $firstUsage->caption;
                    $payload['credit'] = $firstUsage->photo_credit;
                    $payload['link'] = $firstUsage->custom_properties['link'] ?? ($payload['link'] ?? null);
                    $payload['columns'] = $firstUsage->custom_properties['columns'] ?? ($payload['columns'] ?? 2);
                }
            }
        }

        $sectionPayload = [
            'id' => $section->id,
            'block_type' => $section->block_type,
            'type' => $section->block_type,
            'sort_order' => $section->sort_order,
            'text' => $section->text,
            'payload' => $payload,
        ];

        if ($image !== null) {
            $sectionPayload['image'] = $image;
        }

        if ($images !== []) {
            $sectionPayload['images'] = $images;
        }

        return $sectionPayload;
    }
}
