<?php

namespace Database\Factories;

use App\Enums\ImportStatus;
use App\Models\Import;
use App\Models\Supplier;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Import>
 */
class ImportFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'supplier_id' => Supplier::factory(),
            'external_import_id' => 'import-'.fake()->unique()->numerify('########'),
            'sent_at' => now(),
            'status' => ImportStatus::Pending,
            'total_offers' => 0,
            'processed_offers' => 0,
            'error' => null,
            'completed_at' => null,
        ];
    }

    public function processing(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => ImportStatus::Processing,
        ]);
    }

    public function completed(int $totalOffers = 0, ?int $processedOffers = null): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => ImportStatus::Completed,
            'total_offers' => $totalOffers,
            'processed_offers' => $processedOffers ?? $totalOffers,
            'completed_at' => now(),
        ]);
    }

    public function failed(string $error = 'Import failed.'): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => ImportStatus::Failed,
            'error' => $error,
            'completed_at' => now(),
        ]);
    }
}
