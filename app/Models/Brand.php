<?php

namespace App\Models;

use App\Enums\Status;
use Database\Factories\BrandFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property string|null $logo_path
 * @property string|null $country_of_origin
 * @property Status $status
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'slug', 'logo_path', 'country_of_origin', 'status'])]
class Brand extends Model
{
    /** @use HasFactory<BrandFactory> */
    use HasFactory;

    /**
     * Get the tyre models sold under this brand.
     *
     * @return HasMany<TyreModel, $this>
     */
    public function tyreModels(): HasMany
    {
        return $this->hasMany(TyreModel::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => Status::class,
        ];
    }
}
