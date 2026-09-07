<?php

namespace Database\Factories;

use App\Models\Offer;
use App\Models\Property;
use App\Models\Supplier;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Offer>
 */
class OfferFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $checkIn = now()->addDays(fake()->numberBetween(10, 60))->startOfDay();

        return [
            'supplier_id' => Supplier::factory(),
            'property_id' => Property::factory(),
            'last_import_id' => null,
            'external_id' => 'offer-'.fake()->unique()->numerify('########'),
            'check_in' => $checkIn,
            'check_out' => $checkIn->copy()->addDays(fake()->numberBetween(2, 7)),
            'max_guests' => fake()->numberBetween(2, 6),
            'price' => fake()->numberBetween(5_000, 200_000),
            'currency' => 'EUR',
            'available_units' => fake()->numberBetween(1, 5),
            'expires_at' => now()->addDays(7),
            'source_sent_at' => now(),
        ];
    }

    public function forStay(string $checkIn, string $checkOut): static
    {
        return $this->state(fn (array $attributes): array => [
            'check_in' => $checkIn,
            'check_out' => $checkOut,
        ]);
    }

    public function expired(): static
    {
        return $this->state(fn (array $attributes): array => [
            'expires_at' => now()->subDay(),
        ]);
    }

    public function soldOut(): static
    {
        return $this->state(fn (array $attributes): array => [
            'available_units' => 0,
        ]);
    }
}
