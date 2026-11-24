<?php

declare(strict_types=1);

namespace EcApi\Console\Commands;

use EcApi\Models\GalleryUsage;
use EcApi\Models\Post;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class PostPrimaryBannerCommand extends Command
{
    protected $signature = 'posts:primary-banner {--dry-run : Preview changes without writing to the database}';

    protected $description = 'Backfill primary file usage for posts so the latest attached file is marked primary.';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $processed = 0;
        $updated = 0;
        $skipped = 0;

        Post::query()
            ->select(['id', 'photo_credit', 'photo_description', 'photo_alt_text'])
            ->orderBy('id')
            ->with('files')
            ->chunkById(100, function ($posts) use (&$processed, &$updated, &$skipped, $dryRun): void {
                foreach ($posts as $post) {
                    $processed++;

                    $files = $post->files;

                    if ($files->isEmpty()) {
                        $skipped++;
                        $this->line("Post {$post->id}: skipped (no files).");

                        continue;
                    }

                    $primaryPivotId = $files->first()?->pivot?->id;

                    if (! $primaryPivotId) {
                        $skipped++;
                        $this->warn("Post {$post->id}: skipped (missing pivot id).");

                        continue;
                    }

                    $usageIds = $files
                        ->pluck('pivot.id')
                        ->filter()
                        ->map(static fn ($id): int => (int) $id)
                        ->values()
                        ->all();

                    $primaryUpdate = [
                        'photo_credit' => $post->photo_credit,
                        'caption' => $post->photo_description,
                        'alt_text' => $post->photo_alt_text,
                        'is_primary' => true,
                    ];

                    if ($dryRun) {
                        $updated++;
                        $this->line(
                            "Post {$post->id}: would set gallery_usage {$primaryPivotId} as primary " .
                            'and sync photo_credit, caption, alt_text.'
                        );

                        continue;
                    }

                    DB::transaction(function () use ($post, $usageIds, $primaryPivotId, $primaryUpdate): void {
                        GalleryUsage::query()
                            ->where('usage_type', 'posts')
                            ->where('usage_id', $post->id)
                            ->whereIn('id', $usageIds)
                            ->update(['is_primary' => false]);

                        GalleryUsage::query()
                            ->whereKey($primaryPivotId)
                            ->update($primaryUpdate);
                    });

                    $updated++;
                    $this->line(
                        "Post {$post->id}: set gallery_usage {$primaryPivotId} as primary " .
                        'and synced photo_credit, caption, alt_text.'
                    );
                }
            });

        $mode = $dryRun ? 'Dry run' : 'Done';
        $this->info("{$mode}: processed {$processed} posts, updated {$updated}, skipped {$skipped}.");

        return self::SUCCESS;
    }
}
