<?php

namespace App\Models;

use Database\Factories\AuditLogFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $auditable_type
 * @property int $auditable_id
 * @property string $action
 * @property int|null $actor_id
 * @property array<string, mixed>|null $before
 * @property array<string, mixed>|null $after
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['auditable_type', 'auditable_id', 'action', 'actor_id', 'before', 'after'])]
class AuditLog extends Model
{
    /** @use HasFactory<AuditLogFactory> */
    use HasFactory;

    /**
     * Get the model that this audit log entry describes.
     *
     * @return MorphTo<Model, $this>
     */
    public function auditable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Get the staff user who performed the audited action, if known.
     *
     * @return BelongsTo<User, $this>
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'before' => 'array',
            'after' => 'array',
        ];
    }
}
