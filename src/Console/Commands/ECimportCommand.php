<?php

declare(strict_types=1);

namespace EcApi\Console\Commands;

use Illuminate\Console\Command;
use Throwable;

/**
 * Runs the full Everest Chronicle legacy migration pipeline, in order:
 *
 *   1. posts:import-to-articles    posts            → articles + article_versions
 *   2. articles:sync-meta-images   legacy cover/featured images → galleries + gallery_usages
 *   3. sections:fix-image-urls     legacy inline section image URLs → galleries
 *
 * Steps run sequentially in this process; the pipeline stops at the first
 * step that reports a failure so later steps never work on half-migrated data.
 */
final class ECimportCommand extends Command
{
    protected $signature = 'ec:import
        {--dry-run : Preview every step without writing to the database or copying files}
        {--force : Passed to step 1 — re-process already-migrated posts}
        {--fallback-user= : Passed to step 1 — user ID used when a post has no author}
        {--limit= : Passed to step 2 — limit the number of article versions processed}
        {--memory=512M : Passed to steps 2 and 3 — memory limit used while processing images}';

    protected $description = 'Run the Everest Chronicle migration pipeline: posts → articles, article meta images, section image URLs.';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $steps  = $this->steps($dryRun);
        $total  = count($steps);

        $this->newLine();
        $this->line('  <fg=cyan;options=bold>Everest Chronicle Import Pipeline</>');
        $this->line("  <fg=gray>{$total} steps run in sequence; the pipeline stops at the first failure.</>");
        $this->newLine();

        if ($dryRun) {
            $this->warn('  DRY RUN — no database rows will change and no files will be copied.');
            $this->newLine();
        }

        foreach ($steps as $index => [$command, $options]) {
            $position  = $index + 1;
            $startedAt = microtime(true);

            $this->line('  ' . str_repeat('─', 60));
            $this->line("  <options=bold>STEP {$position}/{$total}</>  <fg=cyan>{$command}</>");
            $this->line('  ' . str_repeat('─', 60));

            try {
                $exitCode = $this->call($command, $options);
            } catch (Throwable $exception) {
                $this->newLine();
                $this->error("  Step {$position}/{$total} [{$command}] threw an exception: " . $exception->getMessage());
                $this->error('  Stopping pipeline — later steps were not run.');

                return self::FAILURE;
            }

            if ($exitCode !== self::SUCCESS) {
                $this->newLine();
                $this->error("  Step {$position}/{$total} [{$command}] failed with exit code {$exitCode}.");
                $this->error('  Stopping pipeline — later steps were not run.');

                return self::FAILURE;
            }

            $elapsed = number_format(microtime(true) - $startedAt, 2);
            $this->line("  <fg=green>✓ {$command} finished in {$elapsed}s</>");
            $this->newLine();
        }

        $this->info('  Pipeline complete — all steps finished successfully.');

        return self::SUCCESS;
    }

    /**
     * The ordered pipeline, with the options each wrapped command accepts.
     *
     * @return list<array{0:string, 1:array<string, mixed>}>
     */
    private function steps(bool $dryRun): array
    {
        $importOptions = [];

        if ($dryRun) {
            $importOptions['--dry-run'] = true;
        }

        if ((bool) $this->option('force')) {
            $importOptions['--force'] = true;
        }

        $fallbackUser = $this->option('fallback-user');

        if ($fallbackUser !== null && $fallbackUser !== '') {
            $importOptions['--fallback-user'] = $fallbackUser;
        }

        $imageOptions = [];

        if ($dryRun) {
            $imageOptions['--dry-run'] = true;
        }

        $memory = trim((string) $this->option('memory'));

        if ($memory !== '') {
            $imageOptions['--memory'] = $memory;
        }

        $syncOptions = $imageOptions;
        $limit       = $this->option('limit');

        if ($limit !== null && $limit !== '') {
            $syncOptions['--limit'] = $limit;
        }

        return [
            ['posts:import-to-articles', $importOptions],
            ['articles:sync-meta-images', $syncOptions],
            ['sections:fix-image-urls', $imageOptions],
        ];
    }
}
