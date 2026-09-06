<?php

namespace Database\Factories;

use App\Models\Property;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Property>
 */
class PropertyFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $city = fake()->randomElement(['Barcelona', 'Lisbon', 'Prague', 'Vienna']);

        return [
            'code' => strtoupper(substr($city, 0, 3)).'-'.fake()->unique()->numerify('####'),
            'name' => fake()->streetName().' Apartment',
            'city' => $city,
        ];
    }

    /**
     * Place the property in a specific city.
     */
    public function inCity(string $city): static
    {
        return $this->state(fn (array $attributes): array => [
            'city' => $city,
            'code' => strtoupper(substr($city, 0, 3)).'-'.fake()->unique()->numerify('####'),
        ]);
    }
}
