<?php

namespace App\Actions;

use App\Enums\ImportStatus;
use App\Exceptions\StaleImportException;
use App\Jobs\ProcessImport;
use App\Models\Import;
use App\Models\Supplier;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class RegisterImport
{
    /**
     * Record an incoming import and queue it for processing.
     *
     * @param  array{supplier: string, external_import_id: string, sent_at: string, offers: array<int, array<string, mixed>>}  $payload
     *
     * @throws StaleImportException
     */
    public function __invoke(array $payload): Import
    {
        $supplier = Supplier::where('code', $payload['supplier'])->firstOrFail();
        $sentAt = Carbon::parse($payload['sent_at']);

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

                ProcessImport::dispatch($import->id, $payload['offers'])->afterCommit();

                return $import;
            });
        } catch (UniqueConstraintViolationException) {
            return $this->findExisting($supplier, $payload['external_import_id']);
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
