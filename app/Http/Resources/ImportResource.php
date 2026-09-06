<?php

namespace App\Http\Resources;

use App\Models\Import;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;

/**
 * @mixin Import
 */
class ImportResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
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
            'created_at' => $this->zulu($this->created_at),
            'completed_at' => $this->zulu($this->completed_at),
        ];
    }

    /**
     * Render a timestamp in UTC with whole seconds.
     */
    private function zulu(?Carbon $timestamp): ?string
    {
        return $timestamp?->utc()->toIso8601ZuluString();
    }
}
