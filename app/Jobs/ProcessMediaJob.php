<?php

namespace App\Jobs;

use App\Enums\MediaStatus;
use App\Models\Media;
use App\Services\Media\MediaLibrary;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Converts one uploaded (or previously failed) image to WebP in the
 * background. Retries with backoff; after the last attempt the row stays
 * `failed` with the error so it can be retried from the media manager.
 */
class ProcessMediaJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 120;

    /** @var list<int> */
    public array $backoff = [10, 60];

    public function __construct(public int $mediaId) {}

    public function handle(MediaLibrary $library): void
    {
        $media = Media::query()->find($this->mediaId);

        if ($media === null || $media->status === MediaStatus::Ready) {
            return;
        }

        $library->process($media);
    }

    public function failed(Throwable $e): void
    {
        $media = Media::query()->find($this->mediaId);

        if ($media !== null) {
            app(MediaLibrary::class)->markFailed($media, $e);
        }
    }
}
