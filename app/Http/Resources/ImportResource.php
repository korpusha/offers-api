<?php

namespace App\Http\Resources;

use App\Enums\ImportOfferStatus;
use App\Models\Import;
use App\Models\ImportOffer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;

/**
 * @mixin Import
 */
class ImportResource extends JsonResource
{
    private const SKIPPED_LIMIT = 50;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'supplier' => $this->supplier->code,
            'external_import_id' => $this->external_import_id,
            'sent_at' => $this->zulu($this->sent_at),
            'status' => $this->status->value,
            'total_offers' => $this->total_offers,
            'processed_offers' => $this->processed_offers,
            'error' => $this->error,
            'skipped' => $this->skipped(),
            'created_at' => $this->zulu($this->created_at),
            'completed_at' => $this->zulu($this->completed_at),
        ];
    }

    /**
     * @return array<int, array{external_id: string, code: string|null}>
     */
    private function skipped(): array
    {
        return $this->stagedOffers()
            ->where('status', ImportOfferStatus::Skipped)
            ->orderBy('id')
            ->limit(self::SKIPPED_LIMIT)
            ->get(['external_id', 'error_code'])
            ->map(fn (ImportOffer $offer): array => [
                'external_id' => $offer->external_id,
                'code' => $offer->error_code,
            ])
            ->all();
    }

    private function zulu(?Carbon $timestamp): ?string
    {
        return $timestamp?->utc()->toIso8601ZuluString();
    }
}
