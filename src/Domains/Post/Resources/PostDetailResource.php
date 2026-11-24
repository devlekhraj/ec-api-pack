<?php

declare(strict_types=1);

namespace EcApi\Domains\Post\Resources;

final class PostDetailResource extends PostResource
{
  public function toArray($request): array
  {

    return [
      'id' => $this->id,
      'status' => $this->status,

      // 
      'title' => $this->title,
      'sub_title' => $this->sub_title,
      'slug' => $this->slug,
      'content' => $this->content,

      // author
      'author_id' => $this->author_id,
      'author' => $this->author ? [
        'id' => $this->author->id,
        'name' => $this->author->name,
        'email' => $this->author->email,
      ] : null,
      'has_custom_author' => (bool) $this->has_custom_author,
      'custom_author_name' => $this->custom_author_name,
      'report_from' => $this->report_from,

      'meta' => [
        'meta_title' => $this->meta_title,
        'meta_description' => $this->meta_description,
        'meta_keyword' => $this->meta_keyword,
      ],

      'tags' => $this->tags,
      'views_count' => $this->views_count,
      'is_published' => (bool) $this->is_published,
      'published_date' => $this->published_date,
      'published_on' => $this->published_on,

      'banner_image' => $this->bannerImage ? [
        'id' => $this->bannerImage->id,
        'url' => $this->bannerImage->url,
        'alt_text' => $this->bannerImage->alt_text,
        'photo_credit' => $this->bannerImage->photo_credit,
        'caption' => $this->bannerImage->caption,
      ] : null,
      'category_ids' => $this->categories->pluck('id')->toArray(),
    ];
  }
}
