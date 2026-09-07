<?php

namespace Database\Factories;

use App\Enums\ImportOfferStatus;
use App\Models\Import;
use App\Models\ImportOffer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ImportOffer>
 */
class ImportOfferFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $externalId = 'offer-'.fake()->unique()->numerify('########');

        return [
            'import_id' => Import::factory(),
            'external_id' => $externalId,
            'payload' => [
                'external_id' => $externalId,
                'property' => [
                    'code' => 'BCN-0001',
                    'name' => 'Apartment near Sagrada Familia',
                    'city' => 'Barcelona',
                ],
                'check_in' => '2026-10-10',
                'check_out' => '2026-10-15',
                'max_guests' => 4,
                'price' => 72500,
                'currency' => 'EUR',
                'available_units' => 2,
                'expires_at' => '2026-09-10T23:59:59Z',
            ],
            'status' => ImportOfferStatus::Pending,
            'error_code' => null,
            'error_message' => null,
        ];
    }

    public function applied(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => ImportOfferStatus::Applied,
        ]);
    }

    public function skipped(string $code = 'unexpected_error', string $message = 'Something went wrong.'): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => ImportOfferStatus::Skipped,
            'error_code' => $code,
            'error_message' => $message,
        ]);
    }
}
