<?php

namespace App\Jobs;

use App\Enums\ImportOfferError;
use App\Enums\ImportOfferStatus;
use App\Enums\ImportStatus;
use App\Models\Import;
use App\Models\ImportOffer;
use App\Models\Property;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProcessImport implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $uniqueFor = 3600;

    private const CHUNK = 500;

    private const MESSAGE_BYTES = 1000;

    /**
     * @var array<string, int>
     */
    private array $propertyIds = [];

    public function __construct(
        public int $importId,
    ) {}

    public function uniqueId(): string
    {
        return (string) $this->importId;
    }

    public function handle(): void
    {
        $import = Import::find($this->importId);

        if ($import === null || ! $this->claim($import)) {
            return;
        }

        $this->staged()
            ->where('status', ImportOfferStatus::Pending)
            ->chunkById(self::CHUNK, function ($offers) use ($import): void {
                foreach ($offers as $offer) {
                    $this->applyOffer($import, $offer);
                }
            });

        $import->update([
            'status' => ImportStatus::Completed,
            'processed_offers' => $this->staged()->where('status', ImportOfferStatus::Applied)->count(),
            'error' => $this->skippedSummary(),
            'completed_at' => now(),
        ]);
    }

    public function failed(?Throwable $exception): void
    {
        Log::error('Import failed.', [
            'import_id' => $this->importId,
            'exception' => $exception,
        ]);

        Import::whereKey($this->importId)
            ->whereIn('status', [ImportStatus::Pending, ImportStatus::Processing])
            ->update([
                'status' => ImportStatus::Failed,
                'error' => 'Import failed.',
                'completed_at' => now(),
            ]);
    }

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
     * @return Builder<ImportOffer>
     */
    private function staged(): Builder
    {
        return ImportOffer::where('import_id', $this->importId);
    }

    private function applyOffer(Import $import, ImportOffer $staged): void
    {
        $offer = $staged->payload;

        try {
            $propertyId = DB::transaction(function () use ($import, $offer, $staged): int {
                $propertyId = $this->resolvePropertyId($offer['property']);

                $this->upsertOffer($import, $offer, $propertyId);

                $staged->update([
                    'status' => ImportOfferStatus::Applied,
                    'error_code' => null,
                    'error_message' => null,
                ]);

                return $propertyId;
            });

            $this->propertyIds[$offer['property']['code']] = $propertyId;
        } catch (Throwable $e) {
            $reason = ImportOfferError::for($e);

            if ($reason === null) {
                throw $e;
            }

            Log::warning('Import offer skipped.', [
                'import_id' => $this->importId,
                'external_id' => $staged->external_id,
                'reason' => $reason->value,
                'exception' => $e,
            ]);

            ImportOffer::whereKey($staged->getKey())->update([
                'status' => ImportOfferStatus::Skipped,
                'error_code' => $reason->value,
                'error_message' => mb_strcut($e->getMessage(), 0, self::MESSAGE_BYTES),
            ]);
        }
    }

    /**
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

    private function skippedSummary(): ?string
    {
        $skipped = $this->staged()->where('status', ImportOfferStatus::Skipped)->count();

        if ($skipped === 0) {
            return null;
        }

        return sprintf('Skipped %d of %d offers.', $skipped, $this->staged()->count());
    }
}
