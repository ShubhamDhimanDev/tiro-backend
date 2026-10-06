<?php

namespace App\Http\Controllers\Admin\Media;

use App\Enums\MediaStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Media\MediaUploadRequest;
use App\Jobs\ProcessMediaJob;
use App\Models\Media;
use App\Services\Media\MediaLibrary;
use App\Support\Auth\AdminGuard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Media manager: upload images, watch the background WebP conversion, retry
 * failures and delete unused files. Conversion itself happens in
 * {@see ProcessMediaJob}, never in the request.
 */
class MediaController extends Controller
{
    private const PER_PAGE = 24;

    public function index(Request $request): Response
    {
        $search = trim($request->string('search')->toString());
        $status = MediaStatus::tryFrom($request->string('status')->toString());

        $media = Media::query()
            ->when($search !== '', function ($query) use ($search): void {
                $like = '%'.addcslashes($search, '%_\\').'%';

                $query->where(fn ($inner) => $inner
                    ->where('original_name', 'like', $like)
                    ->orWhere('source_url', 'like', $like));
            })
            ->when($status !== null, fn ($query) => $query->where('status', $status))
            ->latest('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString()
            ->through(fn (Media $item): array => [
                'id' => $item->id,
                'name' => $item->original_name,
                'status' => $item->status->value,
                'error' => $item->error,
                'url' => $item->url(),
                'thumb_url' => $item->thumbUrl(),
                'source_url' => $item->source_url,
                'size' => $item->size,
                'width' => $item->width,
                'height' => $item->height,
                'used_by' => $item->status === MediaStatus::Ready ? $item->usageCount() : 0,
                'created_at' => $item->created_at?->toIso8601String(),
            ]);

        return Inertia::render('media/index', [
            'media' => $media,
            'filters' => [
                'search' => $search !== '' ? $search : null,
                'status' => $status?->value,
            ],
            'counts' => [
                'pending' => Media::query()->whereIn('status', [MediaStatus::Pending, MediaStatus::Processing])->count(),
                'failed' => Media::query()->where('status', MediaStatus::Failed)->count(),
            ],
        ]);
    }

    /**
     * JSON feed for the "choose from media" dialog used by the product,
     * brand and content forms: ready images only, newest first.
     */
    public function picker(Request $request): JsonResponse
    {
        $search = trim($request->string('search')->toString());

        $media = Media::query()
            ->where('status', MediaStatus::Ready)
            ->when($search !== '', fn ($query) => $query->where('original_name', 'like', '%'.addcslashes($search, '%_\\').'%'))
            ->latest('id')
            ->paginate(18)
            ->through(fn (Media $item): array => [
                'id' => $item->id,
                'name' => $item->original_name,
                'url' => $item->url(),
                'thumb_url' => $item->thumbUrl(),
                'width' => $item->width,
                'height' => $item->height,
            ]);

        return response()->json($media);
    }

    public function store(MediaUploadRequest $request, MediaLibrary $library): RedirectResponse|JsonResponse
    {
        $userId = AdminGuard::user($request)->id;

        $created = [];

        foreach ($request->file('files', []) as $file) {
            $created[] = $library->queueUpload($file, $userId);
        }

        // The picker dialog uploads over fetch() and polls `status()` for the
        // conversion result.
        if ($request->wantsJson()) {
            return response()->json([
                'data' => collect($created)->map(fn (Media $media): array => [
                    'id' => $media->id,
                    'name' => $media->original_name,
                    'status' => $media->status->value,
                ])->all(),
            ], 201);
        }

        $count = count($created);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => trans_choice(':count image queued for conversion.|:count images queued for conversion.', $count),
        ]);

        return back();
    }

    /**
     * Conversion progress for a set of just-uploaded images (`?ids=1,2,3`).
     */
    public function status(Request $request): JsonResponse
    {
        $ids = collect(explode(',', $request->string('ids')->toString()))
            ->map(fn (string $id): int => (int) $id)
            ->filter()
            ->take(50)
            ->values();

        $items = Media::query()->whereIn('id', $ids)->get()->map(fn (Media $media): array => [
            'id' => $media->id,
            'name' => $media->original_name,
            'status' => $media->status->value,
            'error' => $media->error,
            'url' => $media->url(),
        ])->all();

        return response()->json(['data' => $items]);
    }

    public function retry(Media $media): RedirectResponse
    {
        if ($media->status !== MediaStatus::Failed) {
            return back();
        }

        $media->update(['status' => MediaStatus::Pending, 'error' => null]);
        ProcessMediaJob::dispatch($media->id);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Retrying conversion.')]);

        return back();
    }

    public function destroy(Media $media, MediaLibrary $library): RedirectResponse
    {
        if ($media->usageCount() > 0) {
            Inertia::flash('toast', [
                'type' => 'error',
                'message' => __('This image is in use (a tyre model, brand logo or content page). Remove it there first.'),
            ]);

            return back();
        }

        $library->deleteFiles($media);
        $media->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Image deleted.')]);

        return back();
    }
}
