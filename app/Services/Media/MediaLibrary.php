<?php

namespace App\Services\Media;

use App\Enums\MediaStatus;
use App\Jobs\ProcessMediaJob;
use App\Models\Media;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * The one place that creates and processes {@see Media} rows. Uploads are
 * stored raw on the private disk and converted later by {@see ProcessMediaJob};
 * remote images are fetched and converted by whoever calls {@see process()}
 * (already inside a queued job).
 */
class MediaLibrary
{
    public function __construct(
        private readonly ImageConverter $converter,
        private readonly RemoteImageFetcher $fetcher,
    ) {}

    /**
     * Store an upload on the private disk, create its `pending` row and queue
     * the WebP conversion.
     */
    public function queueUpload(UploadedFile $file, ?int $uploadedBy): Media
    {
        $incoming = $file->storeAs(
            'media-incoming',
            Str::uuid()->toString().'.'.($file->guessExtension() ?: 'bin'),
            Media::INCOMING_DISK,
        );

        $media = Media::create([
            'original_name' => $file->getClientOriginalName(),
            'incoming_path' => $incoming,
            'size' => $file->getSize(),
            'status' => MediaStatus::Pending,
            'uploaded_by' => $uploadedBy,
        ]);

        ProcessMediaJob::dispatch($media->id);

        return $media;
    }

    /**
     * Find or create the library row for a remote image URL. One row per
     * distinct URL, so an image shared by many products downloads once.
     */
    public function forSourceUrl(string $url): Media
    {
        return Media::firstOrCreate(
            ['source_hash' => Media::hashSourceUrl($url)],
            [
                'source_url' => $url,
                'original_name' => basename((string) parse_url($url, PHP_URL_PATH)) ?: 'image',
                'status' => MediaStatus::Pending,
            ],
        );
    }

    /**
     * Convert (and, for remote images, first download) one media row. Failures
     * are recorded on the row and rethrown so a queued caller can retry.
     */
    public function process(Media $media): void
    {
        $media->update(['status' => MediaStatus::Processing, 'error' => null]);

        try {
            $binary = $this->sourceBinary($media);
            $converted = $this->converter->toWebp($binary);

            $base = 'media/'.now()->format('Y/m').'/'.Str::uuid()->toString();
            $disk = Storage::disk(Media::DISK);
            $disk->put("{$base}.webp", $converted['main']);
            $disk->put("{$base}-thumb.webp", $converted['thumb']);

            $media->update([
                'path' => "{$base}.webp",
                'thumb_path' => "{$base}-thumb.webp",
                'mime' => 'image/webp',
                'size' => strlen($converted['main']),
                'width' => $converted['width'],
                'height' => $converted['height'],
                'status' => MediaStatus::Ready,
                'error' => null,
            ]);

            if ($media->incoming_path !== null) {
                Storage::disk(Media::INCOMING_DISK)->delete($media->incoming_path);
                $media->update(['incoming_path' => null]);
            }
        } catch (Throwable $e) {
            $this->markFailed($media, $e);

            throw $e;
        }
    }

    public function markFailed(Media $media, Throwable $e): void
    {
        $media->update([
            'status' => MediaStatus::Failed,
            'error' => Str::limit($e->getMessage(), 500),
        ]);
    }

    /**
     * Remove the files for a media row (not the row itself).
     */
    public function deleteFiles(Media $media): void
    {
        Storage::disk(Media::DISK)->delete(array_filter([$media->path, $media->thumb_path]));

        if ($media->incoming_path !== null) {
            Storage::disk(Media::INCOMING_DISK)->delete($media->incoming_path);
        }
    }

    private function sourceBinary(Media $media): string
    {
        if ($media->incoming_path !== null) {
            $binary = Storage::disk(Media::INCOMING_DISK)->get($media->incoming_path);

            if ($binary !== null) {
                return $binary;
            }
        }

        if ($media->source_url !== null) {
            return $this->fetcher->fetch($media->source_url);
        }

        throw new \RuntimeException('The original file is no longer available; upload it again.');
    }
}
