<?php

namespace App\Services\Media;

use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\Format;
use Intervention\Image\ImageManager;
use RuntimeException;

/**
 * Converts any supported raster image to WebP using `intervention/image`
 * (GD driver): a display copy capped at {@see MAX_DIMENSION}px and a small
 * thumbnail for the media manager grid.
 */
class ImageConverter
{
    public const MAX_DIMENSION = 1600;

    public const THUMB_DIMENSION = 400;

    private const QUALITY = 82;

    private const THUMB_QUALITY = 75;

    /** Reject absurd pixel counts before GD tries to allocate them. */
    private const MAX_PIXELS = 50_000_000;

    /**
     * @return array{main: string, thumb: string, width: int, height: int}
     */
    public function toWebp(string $binary): array
    {
        $info = @getimagesizefromstring($binary);

        if ($info === false) {
            throw new RuntimeException('The file is not a readable image.');
        }

        if ($info[0] * $info[1] > self::MAX_PIXELS) {
            throw new RuntimeException('The image is too large to process.');
        }

        $manager = ImageManager::usingDriver(Driver::class);

        $main = $manager->decodeBinary($binary)->scaleDown(self::MAX_DIMENSION, self::MAX_DIMENSION);
        $width = $main->width();
        $height = $main->height();

        $thumb = $manager->decodeBinary($binary)->scaleDown(self::THUMB_DIMENSION, self::THUMB_DIMENSION);

        return [
            'main' => (string) $main->encodeUsingFormat(Format::WEBP, quality: self::QUALITY),
            'thumb' => (string) $thumb->encodeUsingFormat(Format::WEBP, quality: self::THUMB_QUALITY),
            'width' => $width,
            'height' => $height,
        ];
    }
}
