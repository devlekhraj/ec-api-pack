<?php

declare(strict_types=1);

namespace EcApi\Console\Commands;

use EcApi\Models\Gallery;
use EcApi\Models\Post;
use Illuminate\Console\Command;

final class ListPostImageUrlsCommand extends Command
{
    protected $signature = 'posts:list-image-urls';

    protected $description = 'List post IDs with matching gallery records for https://everestchronicle.com/image/* URLs found in post content.';

    public function handle(): int
    {
        $processed = 0;
        $matchedPosts = 0;
        $matchedUrls = 0;
        $matchedGalleries = 0;
        $missingGalleries = 0;

        Post::query()
            ->select(['id', 'content'])
            ->orderBy('id')
            ->chunkById(100, function ($posts) use (&$processed, &$matchedPosts, &$matchedUrls, &$matchedGalleries, &$missingGalleries): void {
                foreach ($posts as $post) {
                    $processed++;

                    $urls = $this->extractImageUrls((string) $post->content);

                    if ($urls === []) {
                        continue;
                    }

                    $matchedPosts++;
                    $matchedUrls += count($urls);

                    foreach ($urls as $url) {
                        $filename = $this->extractFilenameFromUrl($url);

                        if ($filename === null) {
                            $missingGalleries++;
                            $this->warn("Post {$post->id}: {$url} (skipped: could not determine filename)");

                            continue;
                        }

                        $gallery = Gallery::query()
                            ->select(['id', 'filename', 'filepath'])
                            ->where('filename', $filename)
                            ->first();

                        if (! $gallery) {
                            $missingGalleries++;
                            $this->warn("Post {$post->id}: {$url} (skipped: no gallery found for {$filename})");

                            continue;
                        }

                        $matchedGalleries++;
                        $cdnUrl = $this->buildCdnUrl((string) $gallery->filepath);
                        $this->line("Post {$post->id}:");
                        $this->line("Gallery {$gallery->id}");
                        $this->line("filename: {$gallery->filename}");
                        $this->line("filepath: {$gallery->filepath}");
                        $this->line("cdn url: {$cdnUrl}");
                        $this->line("url: {$url}");
                        $this->newLine();
                    }
                }
            });

        $this->info(
            "Done: scanned {$processed} posts, matched {$matchedPosts} posts, found {$matchedUrls} URLs, " .
            "matched {$matchedGalleries} galleries, missing {$missingGalleries} gallery lookups."
        );

        return self::SUCCESS;
    }

    /**
     * @return array<int, string>
     */
    private function extractImageUrls(string $content): array
    {
        if ($content === '') {
            return [];
        }

        preg_match_all(
            '/https:\/\/everestchronicle\.com\/image\/[^\s"\'><>]+/i',
            $content,
            $matches
        );

        $urls = array_map(
            static fn (string $url): string => rtrim($url, ".,;:!?)\]}'\""),
            $matches[0] ?? []
        );

        return array_values(array_unique(array_filter($urls, static fn (string $url): bool => $url !== '')));
    }

    private function extractFilenameFromUrl(string $url): ?string
    {
        $path = parse_url($url, PHP_URL_PATH);

        if (! is_string($path) || trim($path) === '') {
            return null;
        }

        $filename = basename($path);

        return $filename !== '' ? $filename : null;
    }

    private function buildCdnUrl(string $filepath): string
    {
        return 'https://cdn.everestchronicle.com/' . ltrim($filepath, '/');
    }
}
