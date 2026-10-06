<?php

namespace App\Enums;

/**
 * Lifecycle of an uploaded/downloaded image as it moves through the queue.
 */
enum MediaStatus: string
{
    /** Stored, waiting for a queue worker to convert it. */
    case Pending = 'pending';

    case Processing = 'processing';

    /** Converted to WebP and served from the public disk. */
    case Ready = 'ready';

    /** Conversion or download failed; see `Media::$error`. Can be retried. */
    case Failed = 'failed';
}
