<?php

namespace App\Models;

use App\Enums\Status;
use Database\Factories\PopularSizeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * An admin-curated size combination (e.g. "225/45R18") that deep-links into
 * a size search — not a product. No order-volume data exists yet to
 * compute "popular" from (see docs/architecture/01-data-model.md).
 *
 * @property int $id
 * @property int $width
 * @property int $profile
 * @property int $rim_diameter
 * @property int $sort_order
 * @property Status $status
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['width', 'profile', 'rim_diameter', 'sort_order', 'status'])]
class PopularSize extends Model
{
    /** @use HasFactory<PopularSizeFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'width' => 'integer',
            'profile' => 'integer',
            'rim_diameter' => 'integer',
            'sort_order' => 'integer',
            'status' => Status::class,
        ];
    }
}
