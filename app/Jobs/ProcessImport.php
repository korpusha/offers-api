<?php

namespace App\Jobs;

use App\Enums\ImportStatus;
use App\Models\Import;
use App\Models\Property;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

class ProcessImport implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $uniqueFor = 3600;

    /**
     * Offers skipped this run, as "external_id: reason".
     *
     * @var list<string>
     */
    private array $skipped = [];

    /**
     * Properties resolved this run, keyed by their code.
     *
     * @var array<string, int>
     */
    private array $propertyIds = [];

    /**
     * @param  array<int, array<string, mixed>>  $offers
     */
    public function __construct(
        public int $importId,
        public array $offers,
    ) {}

    public function uniqueId(): string
    {
        return (string) $this->importId;
    }

    /**
     * Apply the imported offers and settle the import's status.
     */
    public function handle(): void
    {
        $import = Import::find($this->importId);

        if ($import === null || ! $this->claim($import)) {
            return;
        }

        $processed = 0;

        foreach ($this->offers as $offer) {
            if ($this->applyOffer($import, $offer)) {
                $processed++;
            }
        }

        $import->update([
            'status' => ImportStatus::Completed,
            'processed_offers' => $processed,
            'error' => $this->skippedSummary(count($this->offers)),
            'completed_at' => now(),
        ]);
    }

    /**
     * Mark the import failed when the job gives up.
     */
    public function failed(?Throwable $exception): void
    {
        Import::whereKey($this->importId)
            ->whereIn('status', [ImportStatus::Pending, ImportStatus::Processing])
            ->update([
                'status' => ImportStatus::Failed,
                'error' => $exception?->getMessage() ?? 'Import failed.',
                'completed_at' => now(),
            ]);
    }

    /**
     * Take ownership of the import for this run.
     */
    private function claim(Import $import): bool
    {
        $claimed = Import::whereKey($import->getKey())
            ->where('status', ImportStatus::Pending)
            ->update(['status' => ImportStatus::Processing]);

        if ($claimed > 0) {
            return true;
        }

        return Import::whereKey($import->getKey())
            ->where('status', ImportStatus::Processing)
            ->exists();
    }

    /**
     * @param  array<string, mixed>  $offer
     */
    private function applyOffer(Import $import, array $offer): bool
    {
        try {
            $propertyId = DB::transaction(function () use ($import, $offer): int {
                $propertyId = $this->resolvePropertyId($offer['property']);

                $this->upsertOffer($import, $offer, $propertyId);

                return $propertyId;
            });

            $this->propertyIds[$offer['property']['code']] = $propertyId;

            return true;
        } catch (Throwable $e) {
            $this->skipped[] = $offer['external_id'].': '.$e->getMessage();

            return false;
        }
    }

    /**
     * Find the property by code, creating it when the code is new.
     *
     * @param  array{code: string, name: string, city: string}  $property
     */
    private function resolvePropertyId(array $property): int
    {
        if (isset($this->propertyIds[$property['code']])) {
            return $this->propertyIds[$property['code']];
        }

        try {
            $model = Property::firstOrCreate(
                ['code' => $property['code']],
                ['name' => $property['name'], 'city' => $property['city']],
            );
        } catch (UniqueConstraintViolationException) {
            $model = Property::where('code', $property['code'])->firstOrFail();
        }

        return $model->id;
    }

    /**
     * Insert the offer, or update it only with data from a newer import.
     *
     * @param  array<string, mixed>  $offer
     */
    private function upsertOffer(Import $import, array $offer, int $propertyId): void
    {
        $now = now();
        $isNewer = 'new.source_sent_at > offers.source_sent_at';

        $columns = [
            'property_id',
            'last_import_id',
            'check_in',
            'check_out',
            'max_guests',
            'price',
            'currency',
            'available_units',
            'expires_at',
            'updated_at',
        ];

        $assignments = array_map(
            fn (string $column): string => "{$column} = IF({$isNewer}, new.{$column}, offers.{$column})",
            $columns,
        );

        $assignments[] = 'source_sent_at = GREATEST(offers.source_sent_at, new.source_sent_at)';

        DB::insert(
            'INSERT INTO offers (
                supplier_id, property_id, last_import_id, external_id,
                check_in, check_out, max_guests, price, currency,
                available_units, expires_at, source_sent_at, created_at, updated_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?) AS new
            ON DUPLICATE KEY UPDATE '.implode(', ', $assignments),
            [
                $import->supplier_id,
                $propertyId,
                $import->getKey(),
                $offer['external_id'],
                $offer['check_in'],
                $offer['check_out'],
                $offer['max_guests'],
                $offer['price'],
                $offer['currency'],
                $offer['available_units'],
                Carbon::parse($offer['expires_at'])->utc(),
                $import->sent_at,
                $now,
                $now,
            ],
        );
    }

    /**
     * Describe the offers skipped this run, or null when none were.
     */
    private function skippedSummary(int $total): ?string
    {
        if ($this->skipped === []) {
            return null;
        }

        $summary = sprintf('Skipped %d of %d offers. ', count($this->skipped), $total)
            .implode('; ', $this->skipped);

        return mb_substr($summary, 0, 60_000);
    }
}
