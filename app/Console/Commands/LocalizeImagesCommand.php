<?php

namespace App\Console\Commands;

use App\Jobs\LocalizeModelImagesJob;
use App\Models\Media;
use App\Models\TyreModel;
use Illuminate\Console\Command;

/**
 * Backfill: queue a download job for every tyre model that still points at an
 * image on another server (e.g. everything imported from the old WooCommerce
 * export). Safe to re-run — models already on our storage are skipped.
 */
class LocalizeImagesCommand extends Command
{
    protected $signature = 'media:localize-images {--sync : Download inline instead of queueing (slow; for debugging)}';

    protected $description = 'Download externally hosted tyre model images into local storage as WebP';

    public function handle(): int
    {
        $count = 0;

        TyreModel::query()
            ->whereNotNull('images')
            ->orderBy('id')
            ->each(function (TyreModel $model) use (&$count): void {
                $remote = collect($model->images)
                    ->filter(fn ($url) => is_string($url) && ! Media::isLocalUrl($url));

                if ($remote->isEmpty()) {
                    return;
                }

                $count++;

                $this->option('sync')
                    ? LocalizeModelImagesJob::dispatchSync($model->id)
                    : LocalizeModelImagesJob::dispatch($model->id);
            });

        $this->info($this->option('sync')
            ? "Localized images for {$count} tyre model(s)."
            : "Queued {$count} tyre model(s). Make sure a queue worker is running (php artisan queue:work).");

        return self::SUCCESS;
    }
}
