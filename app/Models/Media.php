<?php

namespace App\Models;

use App\Enums\MediaStatus;
use Database\Factories\MediaFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * An image in the media library. Originals are never served: every image is
 * converted to WebP (plus a small thumbnail) by `ProcessMediaJob` and lives
 * on the public disk.
 *
 * @property int $id
 * @property string $original_name
 * @property string|null $path
 * @property string|null $thumb_path
 * @property string|null $incoming_path
 * @property string|null $source_url
 * @property string|null $source_hash
 * @property string|null $mime
 * @property int|null $size
 * @property int|null $width
 * @property int|null $height
 * @property MediaStatus $status
 * @property string|null $error
 * @property int|null $uploaded_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'original_name', 'path', 'thumb_path', 'incoming_path', 'source_url', 'source_hash',
    'mime', 'size', 'width', 'height', 'status', 'error', 'uploaded_by',
])]
class Media extends Model
{
    /** @use HasFactory<MediaFactory> */
    use HasFactory;

    public const DISK = 'public';

    /** Private disk the raw upload waits on until it has been converted. */
    public const INCOMING_DISK = 'local';

    public static function hashSourceUrl(string $url): string
    {
        return sha1($url);
    }

    /**
     * Whether a stored image URL already points at our own storage (or the
     * storefront's own static assets), i.e. needs no downloading.
     */
    public static function isLocalUrl(string $url): bool
    {
        if (str_starts_with($url, '/')) {
            return true;
        }

        return str_starts_with($url, rtrim(Storage::disk(self::DISK)->url(''), '/').'/');
    }

    public function url(): ?string
    {
        return $this->path ? Storage::disk(self::DISK)->url($this->path) : null;
    }

    public function thumbUrl(): ?string
    {
        return $this->thumb_path ? Storage::disk(self::DISK)->url($this->thumb_path) : null;
    }

    /**
     * Number of tyre models whose `images` list uses this file.
     */
    public function usageCount(): int
    {
        $url = $this->url();

        return $url === null ? 0 : TyreModel::query()->whereJsonContains('images', $url)->count();
    }

    /**
     * Get the staff user who uploaded this image, if any.
     *
     * @return BelongsTo<User, $this>
     */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => MediaStatus::class,
            'size' => 'integer',
            'width' => 'integer',
            'height' => 'integer',
        ];
    }
}
