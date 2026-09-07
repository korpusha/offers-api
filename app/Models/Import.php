<?php

namespace App\Models;

use App\Enums\ImportStatus;
use Database\Factories\ImportFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Import extends Model
{
    /** @use HasFactory<ImportFactory> */
    use HasFactory, MassPrunable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'supplier_id',
        'external_import_id',
        'sent_at',
        'status',
        'total_offers',
        'processed_offers',
        'error',
        'completed_at',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sent_at' => 'datetime',
            'completed_at' => 'datetime',
            'status' => ImportStatus::class,
            'total_offers' => 'integer',
            'processed_offers' => 'integer',
        ];
    }

    /**
     * Imports older than the retention window, and the offers staged under
     * them, are pruned by the scheduled model:prune command.
     *
     * @return Builder<static>
     */
    public function prunable(): Builder
    {
        return static::where(
            'created_at', '<', now()->subDays(config('imports.retention_days')),
        );
    }

    /**
     * @return BelongsTo<Supplier, $this>
     */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    /**
     * The offers staged for this import.
     *
     * @return HasMany<ImportOffer, $this>
     */
    public function stagedOffers(): HasMany
    {
        return $this->hasMany(ImportOffer::class);
    }
}
