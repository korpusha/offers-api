<?php

namespace App\Actions;

use App\Enums\ImportOfferStatus;
use App\Enums\ImportStatus;
use App\Exceptions\StaleImportException;
use App\Jobs\ProcessImport;
use App\Models\Import;
use App\Models\ImportOffer;
use App\Models\Supplier;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class RegisterImport
{
    private const STAGE_CHUNK = 1000;

    /**
     * @param  array{supplier: string, external_import_id: string, sent_at: string, offers: array<int, array<string, mixed>>}  $payload
     *
     * @throws StaleImportException
     */
    public function __invoke(array $payload): Import
    {
        $supplier = Supplier::where('code', $payload['supplier'])->firstOrFail();
        $sentAt = Carbon::parse($payload['sent_at'])->utc();

        $existing = $this->findExisting($supplier, $payload['external_import_id']);

        if ($existing !== null) {
            return $existing;
        }

        $this->assertNotStale($supplier, $sentAt);

        try {
            return DB::transaction(function () use ($supplier, $payload, $sentAt): Import {
                $import = Import::create([
                    'supplier_id' => $supplier->id,
                    'external_import_id' => $payload['external_import_id'],
                    'sent_at' => $sentAt,
                    'status' => ImportStatus::Pending,
                    'total_offers' => count($payload['offers']),
                ]);

                $this->stage($import, $payload['offers']);

                ProcessImport::dispatch($import->id)->afterCommit();

                return $import;
            });
        } catch (UniqueConstraintViolationException) {
            return $this->findExisting($supplier, $payload['external_import_id'])
                ?? throw new RuntimeException('Import row vanished after a unique constraint violation.');
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $offers
     */
    private function stage(Import $import, array $offers): void
    {
        $now = now();

        $rows = array_map(fn (array $offer): array => [
            'import_id' => $import->getKey(),
            'external_id' => $offer['external_id'],
            'payload' => json_encode($offer, JSON_THROW_ON_ERROR),
            'status' => ImportOfferStatus::Pending->value,
            'created_at' => $now,
            'updated_at' => $now,
        ], $offers);

        foreach (array_chunk($rows, self::STAGE_CHUNK) as $chunk) {
            ImportOffer::insert($chunk);
        }
    }

    private function findExisting(Supplier $supplier, string $externalImportId): ?Import
    {
        return Import::where('supplier_id', $supplier->id)
            ->where('external_import_id', $externalImportId)
            ->first();
    }

    /**
     * @throws StaleImportException
     */
    private function assertNotStale(Supplier $supplier, Carbon $sentAt): void
    {
        $latestSentAt = Import::where('supplier_id', $supplier->id)->max('sent_at');

        if ($latestSentAt !== null && $sentAt->lessThanOrEqualTo(Carbon::parse($latestSentAt))) {
            throw new StaleImportException;
        }
    }
}
