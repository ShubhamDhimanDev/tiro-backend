<?php

namespace App\Jobs;

use App\Enums\MediaStatus;
use App\Models\Media;
use App\Models\TyreModel;
use App\Services\Media\MediaLibrary;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Downloads every externally-hosted image of one tyre model into our own
 * storage (as WebP) and rewrites `tyre_models.images` to the local URLs.
 *
 * Images that fail to download keep their original URL, so the storefront
 * keeps working; the failure is recorded on the media row and can be retried
 * from the media manager or by re-running `media:localize-images`.
 */
class LocalizeModelImagesJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 600;

    public function __construct(public int $tyreModelId) {}

    public function handle(MediaLibrary $library): void
    {
        $model = TyreModel::query()->find($this->tyreModelId);

        if ($model === null || empty($model->images)) {
            return;
        }

        $changed = false;
        $images = [];

        foreach ($model->images as $url) {
            if (! is_string($url) || Media::isLocalUrl($url)) {
                $images[] = $url;

                continue;
            }

            $localUrl = $this->localize($library, $url);

            $images[] = $localUrl ?? $url;
            $changed = $changed || $localUrl !== null;
        }

        if ($changed) {
            $model->update(['images' => array_values(array_unique($images))]);
        }
    }

    private function localize(MediaLibrary $library, string $url): ?string
    {
        $media = $library->forSourceUrl($url);

        if ($media->status !== MediaStatus::Ready) {
            // A previous failure is retried; a row another job is converting
            // right now is left for that job.
            if ($media->status === MediaStatus::Processing && $media->updated_at?->gt(now()->subMinutes(5))) {
                return null;
            }

            try {
                $library->process($media);
            } catch (Throwable $e) {
                Log::warning('Could not localize image.', ['url' => $url, 'error' => $e->getMessage()]);

                return null;
            }
        }

        return $media->fresh()?->url();
    }
}
