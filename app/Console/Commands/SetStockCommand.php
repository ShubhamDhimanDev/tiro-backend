<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\InventoryItem;
use App\Models\StockLocation;
use App\Models\TyreVariant;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Sets `qty_on_hand` to one value for every tyre variant at the chosen stock
 * location(s), creating the stock row where a variant has none there yet.
 * `qty_reserved` is never touched, so holds and bookings stay intact.
 *
 * Zone availability is summed across every location a zone is linked to, so
 * with several locations "20" becomes 20 per location. To avoid doubling
 * stock by accident the command won't guess: with more than one location it
 * needs `--location` or `--all-locations`.
 */
#[Signature('inventory:set-stock {quantity=20 : Units on hand to set for every variant} {--location=* : Stock location ID to update (repeatable)} {--all-locations : Update every stock location} {--dry-run : Show what would change without changing anything} {--force : Skip the confirmation prompt}')]
#[Description('Set qty_on_hand for every tyre variant at one or more stock locations (creates missing stock rows)')]
class SetStockCommand extends Command
{
    private const CHUNK_SIZE = 500;

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $quantity = $this->argument('quantity');

        if (! is_numeric($quantity) || (int) $quantity < 0 || (string) (int) $quantity !== (string) $quantity) {
            $this->error('The quantity must be a whole number of 0 or more.');

            return self::FAILURE;
        }

        $quantity = (int) $quantity;
        $locations = $this->resolveLocations();

        if ($locations === null) {
            return self::FAILURE;
        }

        $variantCount = TyreVariant::query()->count();

        if ($variantCount === 0) {
            $this->warn('There are no tyre variants, nothing to do.');

            return self::SUCCESS;
        }

        $rows = $locations->map(function (StockLocation $location) use ($variantCount, $quantity): array {
            $existing = InventoryItem::query()->where('stock_location_id', $location->id)->count();
            $overReserved = InventoryItem::query()
                ->where('stock_location_id', $location->id)
                ->where('qty_reserved', '>', $quantity)
                ->count();

            return [
                'id' => $location->id,
                'name' => $location->name,
                'update' => $existing,
                'create' => $variantCount - $existing,
                'over_reserved' => $overReserved,
            ];
        });

        $this->info(sprintf('Set qty_on_hand to %d for all %d variant(s) at:', $quantity, $variantCount));
        $this->table(
            ['Location ID', 'Location', 'Rows to update', 'Rows to create', 'Reserved above new stock'],
            $rows->map(fn (array $row) => array_values($row))->all(),
        );

        if ($rows->sum('over_reserved') > 0) {
            $this->warn('Some rows have more reserved than the new stock level; they will show as out of stock until those holds clear.');
        }

        if ($this->option('dry-run')) {
            $this->comment('[dry-run] Nothing was changed.');

            return self::SUCCESS;
        }

        if (! $this->option('force') && ! $this->confirm('Apply this change?')) {
            $this->comment('Cancelled, nothing was changed.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($locations, $quantity, $rows): void {
            $now = Carbon::now();

            foreach ($locations as $location) {
                TyreVariant::query()->select('id')->orderBy('id')->chunk(self::CHUNK_SIZE, function ($variants) use ($location, $quantity, $now): void {
                    InventoryItem::query()->upsert(
                        $variants->map(fn (TyreVariant $variant): array => [
                            'tyre_variant_id' => $variant->id,
                            'stock_location_id' => $location->id,
                            'qty_on_hand' => $quantity,
                            'qty_reserved' => 0,
                            'reorder_point' => 0,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ])->all(),
                        ['tyre_variant_id', 'stock_location_id'],
                        ['qty_on_hand', 'updated_at'],
                    );
                });
            }

            AuditLog::create([
                'auditable_type' => InventoryItem::class,
                'auditable_id' => 0,
                'action' => 'inventory.bulk_set_stock',
                'actor_id' => null,
                'before' => null,
                'after' => ['quantity' => $quantity, 'locations' => $rows->all()],
            ]);
        });

        $this->info(sprintf('Done: %d location(s) updated.', $locations->count()));

        return self::SUCCESS;
    }

    /**
     * @return Collection<int, StockLocation>|null Null when the options were unusable (error already printed).
     */
    private function resolveLocations(): ?Collection
    {
        $all = StockLocation::query()->orderBy('id')->get();

        if ($all->isEmpty()) {
            $this->error('There are no stock locations. Create one first.');

            return null;
        }

        if ($this->option('all-locations')) {
            return $all;
        }

        $requested = collect((array) $this->option('location'))->map(fn ($id) => (int) $id)->unique();

        if ($requested->isEmpty()) {
            if ($all->count() === 1) {
                return $all;
            }

            $this->error('There are '.$all->count().' stock locations. Pass --location=ID (repeatable) or --all-locations.');
            $this->table(['ID', 'Name'], $all->map(fn (StockLocation $location) => [$location->id, $location->name])->all());

            return null;
        }

        $unknown = $requested->diff($all->pluck('id'));

        if ($unknown->isNotEmpty()) {
            $this->error('Unknown stock location ID(s): '.$unknown->implode(', '));

            return null;
        }

        return $all->whereIn('id', $requested->all())->values();
    }
}
