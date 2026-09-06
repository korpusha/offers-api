<?php

namespace Tests\Feature;

use App\Models\Offer;
use App\Models\Property;
use App\Models\Supplier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class SearchPropertiesTest extends TestCase
{
    use RefreshDatabase;

    private const CHECK_IN = '2026-10-10';

    private const CHECK_OUT = '2026-10-15';

    public function test_it_returns_the_cheapest_offer_for_each_property(): void
    {
        $property = Property::factory()->inCity('Barcelona')->create(['code' => 'BCN-0001']);
        $cheap = Supplier::factory()->create(['code' => 'supplier-a']);
        $pricey = Supplier::factory()->create(['code' => 'supplier-b']);

        $this->offer($property, $pricey, price: 90000);
        $this->offer($property, $cheap, price: 72500, availableUnits: 2);

        $this->search()
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.code', 'BCN-0001')
            ->assertJsonPath('data.0.city', 'Barcelona')
            ->assertJsonPath('data.0.best_offer.supplier', 'supplier-a')
            ->assertJsonPath('data.0.best_offer.price', 72500)
            ->assertJsonPath('data.0.best_offer.currency', 'EUR')
            ->assertJsonPath('data.0.best_offer.available_units', 2);
    }

    public function test_it_orders_properties_by_their_best_price(): void
    {
        $this->propertyWithOffer('BCN-0003', price: 90000);
        $this->propertyWithOffer('BCN-0001', price: 50000);
        $this->propertyWithOffer('BCN-0002', price: 70000);

        $this->search()
            ->assertOk()
            ->assertJsonPath('data.0.code', 'BCN-0001')
            ->assertJsonPath('data.1.code', 'BCN-0002')
            ->assertJsonPath('data.2.code', 'BCN-0003');
    }

    public function test_it_skips_offers_that_do_not_match_the_dates_exactly(): void
    {
        $property = Property::factory()->inCity('Barcelona')->create();

        $this->offer($property, checkIn: '2026-10-10', checkOut: '2026-10-20');

        $this->search()->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_it_skips_offers_that_cannot_hold_the_party(): void
    {
        $property = Property::factory()->inCity('Barcelona')->create();
        $this->offer($property, maxGuests: 2);

        $this->search(guests: 4)->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_it_skips_expired_and_sold_out_offers(): void
    {
        $expired = Property::factory()->inCity('Barcelona')->create();
        Offer::factory()->expired()->forStay(self::CHECK_IN, self::CHECK_OUT)
            ->for($expired)->create();

        $soldOut = Property::factory()->inCity('Barcelona')->create();
        Offer::factory()->soldOut()->forStay(self::CHECK_IN, self::CHECK_OUT)
            ->for($soldOut)->create();

        $this->search()->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_a_property_falls_back_to_its_next_cheapest_bookable_offer(): void
    {
        $property = Property::factory()->inCity('Barcelona')->create(['code' => 'BCN-0001']);

        Offer::factory()->expired()->forStay(self::CHECK_IN, self::CHECK_OUT)
            ->for($property)->create(['price' => 10000]);

        $this->offer($property, price: 72500);

        $this->search()
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.best_offer.price', 72500);
    }

    public function test_it_filters_by_city_when_given(): void
    {
        $this->propertyWithOffer('BCN-0001', city: 'Barcelona');
        $this->propertyWithOffer('LIS-0001', city: 'Lisbon');

        $this->search(city: 'Barcelona')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.code', 'BCN-0001');
    }

    public function test_it_returns_every_city_when_none_is_given(): void
    {
        $this->propertyWithOffer('BCN-0001', city: 'Barcelona');
        $this->propertyWithOffer('LIS-0001', city: 'Lisbon');

        $this->search(city: null)->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_a_property_appears_once_even_with_many_matching_offers(): void
    {
        $property = Property::factory()->inCity('Barcelona')->create();

        foreach ([90000, 72500, 81000] as $price) {
            $this->offer($property, price: $price);
        }

        $this->search()
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.best_offer.price', 72500);
    }

    public function test_pagination_is_stable_when_prices_tie(): void
    {
        foreach (range(1, 20) as $n) {
            $this->propertyWithOffer(sprintf('BCN-%04d', $n), price: 72500);
        }

        $firstPage = $this->search()->assertOk();
        $secondPage = $this->search(page: 2)->assertOk();

        $codes = array_merge(
            array_column($firstPage->json('data'), 'code'),
            array_column($secondPage->json('data'), 'code'),
        );

        $this->assertCount(20, $codes);
        $this->assertSame($codes, array_unique($codes));
    }

    public function test_it_exposes_the_pagination_controls(): void
    {
        foreach (range(1, 20) as $n) {
            $this->propertyWithOffer(sprintf('BCN-%04d', $n));
        }

        $response = $this->search()->assertOk();

        $this->assertJsonStructureHasPagination($response->json());
        $this->assertSame(15, $response->json('meta.per_page'));
        $this->assertSame(20, $response->json('meta.total'));
        $this->assertNotNull($response->json('links.next'));
        $this->assertNull($response->json('links.prev'));
        $this->assertCount(15, $response->json('data'));

        $second = $this->search(page: 2)->assertOk();
        $this->assertCount(5, $second->json('data'));
        $this->assertNotNull($second->json('links.prev'));
        $this->assertNull($second->json('links.next'));
    }

    public function test_the_page_size_cannot_be_widened_by_the_client(): void
    {
        foreach (range(1, 20) as $n) {
            $this->propertyWithOffer(sprintf('BCN-%04d', $n));
        }

        $this->getJson($this->url([
            'city' => 'Barcelona',
            'check_in' => self::CHECK_IN,
            'check_out' => self::CHECK_OUT,
            'guests' => 2,
            'per_page' => 100,
        ]))
            ->assertOk()
            ->assertJsonCount(15, 'data')
            ->assertJsonPath('meta.per_page', 15);
    }

    public function test_it_rejects_a_search_without_dates_or_guests(): void
    {
        $this->getJson('/api/properties')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['check_in', 'check_out', 'guests']);
    }

    public function test_it_rejects_a_checkout_that_is_not_after_checkin(): void
    {
        $this->getJson($this->url([
            'check_in' => self::CHECK_IN,
            'check_out' => self::CHECK_IN,
            'guests' => 2,
        ]))->assertUnprocessable()->assertJsonValidationErrors('check_out');
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function assertJsonStructureHasPagination(array $payload): void
    {
        $this->assertArrayHasKey('links', $payload);
        $this->assertArrayHasKey('meta', $payload);
        $this->assertArrayHasKey('next', $payload['links']);
        $this->assertArrayHasKey('prev', $payload['links']);
        $this->assertArrayHasKey('per_page', $payload['meta']);
    }

    private function propertyWithOffer(
        string $code,
        string $city = 'Barcelona',
        int $price = 72500,
    ): Property {
        $property = Property::factory()->inCity($city)->create(['code' => $code]);
        $this->offer($property, price: $price);

        return $property;
    }

    private function offer(
        Property $property,
        ?Supplier $supplier = null,
        int $price = 72500,
        int $availableUnits = 2,
        int $maxGuests = 4,
        string $checkIn = self::CHECK_IN,
        string $checkOut = self::CHECK_OUT,
    ): Offer {
        return Offer::factory()
            ->for($property)
            ->for($supplier ?? Supplier::factory())
            ->forStay($checkIn, $checkOut)
            ->create([
                'price' => $price,
                'available_units' => $availableUnits,
                'max_guests' => $maxGuests,
                'currency' => 'EUR',
            ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function url(array $overrides = []): string
    {
        return '/api/properties?'.http_build_query($overrides);
    }

    private function search(
        ?string $city = 'Barcelona',
        int $guests = 2,
        int $page = 1,
    ): TestResponse {
        return $this->getJson($this->url(array_filter([
            'city' => $city,
            'check_in' => self::CHECK_IN,
            'check_out' => self::CHECK_OUT,
            'guests' => $guests,
            'page' => $page,
        ], fn ($value) => $value !== null)));
    }
}
