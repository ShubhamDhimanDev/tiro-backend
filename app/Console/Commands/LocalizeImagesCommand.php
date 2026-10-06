<?php

namespace App\Console\Commands;

use App\Enums\MediaStatus;
use App\Jobs\LocalizeModelImagesJob;
use App\Models\Media;
use App\Models\TyreModel;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Backfill: download every product image that is still hosted on another
 * server (e.g. products imported before image localization existed) into our
 * own storage, converted to WebP, and rewrite `tyre_models.images` to the new
 * URLs. Safe to re-run — models already on our storage are skipped, and an
 * image shared by many products is only downloaded once.
 *
 * By default each model is queued (needs `php artisan queue:work`); `--sync`
 * does the work inline with a progress bar, which is the simplest way to run
 * it once on a server.
 */
class LocalizeImagesCommand extends Command
{
    protected $signature = 'media:localize-images
        {--sync : Download and convert inline with a progress bar instead of queueing}
        {--dry-run : Only report what would be downloaded}
        {--limit= : Only process the first N tyre models}
        {--model= : Only process this tyre model id}';

    protected $description = 'Download externally hosted tyre model images into local storage as WebP';

    public function handle(): int
    {
        $models = $this->modelsWithRemoteImages();
        $limit = $this->option('limit');

        if (is_numeric($limit) && (int) $limit > 0) {
            $models = $models->take((int) $limit);
        }

        if ($models->isEmpty()) {
            $this->info('Nothing to do: every product image is already on local storage.');

            return self::SUCCESS;
        }

        $urls = $models->flatMap(fn (TyreModel $model) => $this->remoteUrls($model))->unique();

        $this->line(sprintf(
            '%d tyre model(s) reference %d distinct remote image(s).',
            $models->count(),
            $urls->count(),
        ));

        if ($this->option('dry-run')) {
            $this->info('Dry run — nothing was downloaded.');

            return self::SUCCESS;
        }

        if (! $this->option('sync')) {
            $models->each(fn (TyreModel $model) => LocalizeModelImagesJob::dispatch($model->id));

            $this->info("Queued {$models->count()} job(s). A queue worker must be running: php artisan queue:work");
            $this->line('Watch progress in the admin under Media, or re-run with --dry-run to see what is left.');

            return self::SUCCESS;
        }

        $this->output->progressStart($models->count());
        $models->each(function (TyreModel $model): void {
            LocalizeModelImagesJob::dispatchSync($model->id);
            $this->output->progressAdvance();
        });
        $this->output->progressFinish();

        $failed = Media::query()->where('status', MediaStatus::Failed)->whereNotNull('source_url')->count();
        $remaining = $this->modelsWithRemoteImages()->count();

        $this->info('Done.');
        $this->line("Models still pointing at a remote image: {$remaining}");

        if ($failed > 0) {
            $this->warn("{$failed} image(s) could not be downloaded; see Media → Failed in the admin (they can be retried there). The affected products keep their original URL.");
        }

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @return Collection<int, TyreModel>
     */
    private function modelsWithRemoteImages()
    {
        return TyreModel::query()
            ->whereNotNull('images')
            ->when($this->option('model'), fn (Builder $query, $id) => $query->whereKey($id))
            ->orderBy('id')
            ->get()
            ->filter(fn (TyreModel $model) => $this->remoteUrls($model)->isNotEmpty())
            ->values();
    }

    /**
     * @return Collection<int, string>
     */
    private function remoteUrls(TyreModel $model)
    {
        return collect($model->images)
            ->filter(fn ($url) => is_string($url) && $url !== '' && ! Media::isLocalUrl($url))
            ->values();
    }
}
