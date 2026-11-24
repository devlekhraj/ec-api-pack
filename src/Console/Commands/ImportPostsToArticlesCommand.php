<?php

declare(strict_types=1);

namespace EcApi\Console\Commands;

use EcApi\Models\Article;
use EcApi\Models\ArticleVersion;
use EcApi\Models\ArticleVersionSeo;
use EcApi\Models\ContentSection;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

final class ImportPostsToArticlesCommand extends Command
{
    protected $signature = 'posts:import-to-articles
                            {--dry-run : Preview what would be imported without writing anything}
                            {--force : Re-process already-migrated posts and update meta/author fields}
                            {--fallback-user= : User ID to use when a post has no author_id}
                            {--chunk=100 : Number of posts to process per batch}';

    protected $description = 'Import posts table data into articles + article_versions. Sets posts.is_published = 1 as a migration marker on success.';

    private int $imported  = 0;
    private int $skipped   = 0;
    private int $updated   = 0;
    private int $warnings  = 0;
    private int $errors    = 0;
    private bool $isDryRun = false;
    private bool $isForce  = false;

    public function handle(): int
    {
        $this->isDryRun     = (bool) $this->option('dry-run');
        $this->isForce      = (bool) $this->option('force');
        $fallbackUserId     = $this->option('fallback-user') ? (int) $this->option('fallback-user') : null;
        $chunkSize          = max(1, (int) ($this->option('chunk') ?? 100));

        if ($this->isDryRun) {
            $this->warn('DRY-RUN mode — no changes will be written.');
        }

        if ($this->isForce) {
            $this->warn('FORCE mode — already-migrated posts will be updated.');
        }

        // Validate fallback user if provided
        if ($fallbackUserId !== null && ! DB::table('users')->where('id', $fallbackUserId)->exists()) {
            $this->error("Fallback user ID {$fallbackUserId} does not exist.");
            return self::FAILURE;
        }

        // Check for null authors — default fallback to user ID 1 if not provided
        $nullAuthorCount = DB::table('posts')->whereNull('author_id')->count();
        if ($nullAuthorCount > 0 && $fallbackUserId === null) {
            $fallbackUserId = 1;
            $this->warn("{$nullAuthorCount} post(s) have null author_id. Defaulting to fallback user #1.");
        }

        // Collect already-migrated post IDs (via meta->legacy_post_id in article_versions)
        $migratedPostIds = DB::table('article_versions')
            ->whereNotNull('meta')
            ->get(['meta'])
            ->map(fn ($row) => data_get(json_decode((string) $row->meta, true), 'legacy_post_id'))
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->flip()
            ->all(); // [ post_id => true ] for O(1) lookup

        $totalPosts = DB::table('posts')->count();
        $this->info("Starting import: {$totalPosts} total posts, " . count($migratedPostIds) . " already migrated.");

        DB::table('posts')
            ->orderBy('id')
            ->chunkById($chunkSize, function ($posts) use ($migratedPostIds, $fallbackUserId): void {
                foreach ($posts as $post) {
                    $this->processPost($post, $migratedPostIds, $fallbackUserId);
                }
            });

        if ($this->isForce) {
            $this->newLine();
            $this->info("Done: {$this->imported} imported, {$this->updated} updated, {$this->skipped} skipped, {$this->warnings} warnings, {$this->errors} errors.");
        }

        if (! $this->isForce) {
            $this->newLine();
            $this->info("Done: {$this->imported} imported, {$this->skipped} skipped, {$this->warnings} warnings, {$this->errors} errors.");
        }

        return $this->errors > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function processPost(object $post, array $migratedPostIds, ?int $fallbackUserId): void
    {
        // --- Idempotency check ---
        if (isset($migratedPostIds[$post->id])) {
            if ($this->isForce) {
                $this->updatePost($post, $fallbackUserId);
            } else {
                $this->line("  [SKIP] Post #{$post->id} — already migrated.");
                $this->skipped++;
            }
            return;
        }

        // --- Resolve author ---
        $authorId    = $post->author_id ? (int) $post->author_id : null;
        $createdById = $authorId ?? $fallbackUserId;

        if ($authorId === null) {
            $this->warn("  [WARN] Post #{$post->id} — null author_id, using fallback user #{$fallbackUserId}.");
            $this->warnings++;
        }

        // --- Determine publication state ---
        $isPublished = (bool) $post->is_published;
        $publishedAt = $isPublished && ! empty($post->published_on) ? $post->published_on : null;

        // --- Dry-run preview ---
        if ($this->isDryRun) {
            $statusLabel = $isPublished ? 'published' : 'draft';
            $this->line("  [DRY-RUN] Post #{$post->id} \"{$post->title}\" → would create article ({$statusLabel})");
            $this->imported++;
            return;
        }

        // --- Import within a transaction ---
        $article = null;
        $version = null;

        try {
            DB::transaction(function () use ($post, $isPublished, $publishedAt, $createdById, $authorId, &$article, &$version): void {
                // Step 1: Create Article
                $article = Article::query()->create([
                    'status'             => $isPublished ? Article::STATUS_PUBLISHED : Article::STATUS_DRAFT,
                    'created_by_id'      => $createdById,
                    'first_published_at' => $publishedAt,
                    'last_published_at'  => $publishedAt,
                ]);

                // Step 2: Create ArticleVersion
                $versionMeta = array_merge([
                    'legacy_post_id'     => $post->id,
                    'is_featured'        => (bool) ($post->is_featured ?? false),
                    'is_home_featured'   => (bool) ($post->is_home_featured ?? false),
                    'tags'               => $post->tags ?? null,
                    'views_count'        => (int) ($post->views_count ?? 0),
                    'has_custom_author'  => (bool) ($post->has_custom_author ?? false),
                    'custom_author_name' => $post->custom_author_name ?? null,
                ], $this->buildImageMeta($post));

                $version = ArticleVersion::query()->create([
                    'article_id'      => $article->id,
                    'version_type'    => ArticleVersion::TYPE_ORIGINAL,
                    'status'          => $isPublished ? ArticleVersion::STATUS_PUBLISHED : ArticleVersion::STATUS_DRAFT,
                    'title'           => (string) $post->title,
                    'sub_title'       => $post->sub_title ?? null,
                    'slug'            => (string) $post->slug,
                    'report_from'     => $post->report_from ?? null,
                    'meta'            => $versionMeta,
                    'created_by_id'   => $createdById,
                    'published_by_id' => $isPublished ? $createdById : null,
                    'published_at'    => $publishedAt,
                ]);

                // Step 3: Update Article with version pointers
                $article->forceFill([
                    'latest_version_id'    => $version->id,
                    'published_version_id' => $isPublished ? $version->id : null,
                ])->save();

                // Step 4: Author pivot
                if ($authorId !== null) {
                    $version->authors()->attach($authorId, ['display_order' => 1]);
                }

                // Step 5: Category pivot (prefer category_post, fallback to post.category_id)
                $categoryIds = DB::table('category_post')
                    ->where('post_id', $post->id)
                    ->pluck('category_id')
                    ->map(fn ($id) => (int) $id)
                    ->all();

                if (empty($categoryIds) && ! empty($post->category_id)) {
                    $categoryIds = [(int) $post->category_id];
                }

                if (! empty($categoryIds)) {
                    $version->categories()->sync($categoryIds);
                }

                // Step 6: SEO row (only if any field has data)
                $metaTitle = $this->normalizeText($post->meta_title ?? null);
                $metaDesc  = $this->normalizeText($post->meta_description ?? null);
                $metaKw    = $this->normalizeText($post->meta_keyword ?? null);

                if ($metaTitle !== null || $metaDesc !== null || $metaKw !== null) {
                    ArticleVersionSeo::query()->create([
                        'article_version_id' => $version->id,
                        'meta_title'         => $metaTitle,
                        'meta_descriptions'  => $metaDesc,
                        'focus_keywords'     => $metaKw,
                    ]);
                }

                // Step 7: Section — map post.content as one rich_text block
                $content = $this->normalizeText($post->content ?? null);
                if ($content !== null) {
                    $version->sections()->create([
                        'block_type' => ContentSection::BLOCK_TYPE_PARAGRAPH,
                        'sort_order' => 0,
                        'text'       => $content,
                        'payload'    => [],
                    ]);
                }

                // Step 8: Mark the source post as migrated (is_published = 1)
                DB::table('posts')
                    ->where('id', $post->id)
                    ->update(['is_published' => 1]);
            });

            assert($article !== null);
            assert($version !== null);
            $statusLabel = $isPublished ? 'published' : 'draft';
            $this->line("  [OK]  Post #{$post->id} → article #{$article->id}, version #{$version->id} ({$statusLabel})");
            $this->imported++;

        } catch (Throwable $th) {
            $this->error("  [ERR] Post #{$post->id} — {$th->getMessage()}");
            Log::error('ImportPostsToArticles: failed to import post', [
                'post_id' => $post->id,
                'error'   => $th->getMessage(),
                'trace'   => $th->getTraceAsString(),
            ]);
            $this->errors++;
        }
    }

    private function updatePost(object $post, ?int $fallbackUserId): void
    {
        try {
            // Find the existing ArticleVersion by legacy_post_id in meta
            $versionRow = DB::table('article_versions')
                ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(meta, '$.legacy_post_id')) = ?", [$post->id])
                ->first(['id', 'article_id', 'meta', 'created_by_id']);

            if ($versionRow === null) {
                $this->warn("  [WARN] Post #{$post->id} — marked migrated but no article_version found. Skipping.");
                $this->warnings++;
                return;
            }

            $authorId    = $post->author_id ? (int) $post->author_id : null;
            $createdById = $authorId ?? $fallbackUserId ?? 1;

            $existingMeta = json_decode((string) $versionRow->meta, true) ?? [];
            $updatedMeta  = array_merge($existingMeta, $this->buildImageMeta($post));

            DB::transaction(function () use ($versionRow, $updatedMeta, $createdById): void {
                // Update article_version meta + fix created_by_id if null
                DB::table('article_versions')
                    ->where('id', $versionRow->id)
                    ->update([
                        'meta'           => json_encode($updatedMeta),
                        'created_by_id'  => $versionRow->created_by_id ?? $createdById,
                    ]);

                // Fix article.created_by_id if null
                DB::table('articles')
                    ->where('id', $versionRow->article_id)
                    ->whereNull('created_by_id')
                    ->update(['created_by_id' => $createdById]);
            });

            $this->line("  [UPDATE] Post #{$post->id} → version #{$versionRow->id} meta + author patched.");
            $this->updated++;

        } catch (Throwable $th) {
            $this->error("  [ERR] Post #{$post->id} (update) — {$th->getMessage()}");
            Log::error('ImportPostsToArticles: failed to update post', [
                'post_id' => $post->id,
                'error'   => $th->getMessage(),
                'trace'   => $th->getTraceAsString(),
            ]);
            $this->errors++;
        }
    }

    private function normalizeText(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim($value);

        if ($trimmed === '' || strtolower($trimmed) === 'null') {
            return null;
        }

        return $trimmed;
    }

    /**
     * Returns an array with exactly ONE image key set (cover_image or featured_image),
     * based on which URL is populated. The unused key is omitted entirely.
     *
     * @return array<string, array<string, string|null>>
     */
    private function buildImageMeta(object $post): array
    {
        $imageData = [
            'photo_alt_text'    => $this->normalizeText($post->photo_alt_text ?? null),
            'photo_credit'      => $this->normalizeText($post->photo_credit ?? null),
            'photo_description' => $this->normalizeText($post->photo_description ?? null),
        ];

        if (! empty($post->cover_image)) {
            return ['cover_image' => array_merge(['url' => $post->cover_image], $imageData)];
        }

        if (! empty($post->featured_image)) {
            return ['featured_image' => array_merge(['url' => $post->featured_image], $imageData)];
        }

        return [];
    }
}
