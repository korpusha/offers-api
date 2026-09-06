<?php

namespace Tests\Feature;

use App\Models\Offer;
use App\Models\Reservation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class StoreReservationTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_a_reservation_and_takes_a_unit(): void
    {
        $offer = Offer::factory()->create(['available_units' => 2]);

        $this->reserve($offer)
            ->assertCreated()
            ->assertJsonPath('data.offer_id', $offer->id)
            ->assertJsonPath('data.client_reference', 'web-order-9f782b1c')
            ->assertJsonPath('data.customer_name', 'John Smith')
            ->assertJsonPath('data.customer_email', 'john@example.com')
            ->assertJsonStructure(['data' => ['id', 'offer_id', 'client_reference', 'customer_name', 'customer_email', 'created_at']]);

        $this->assertSame(1, $offer->refresh()->available_units);
        $this->assertSame(1, Reservation::count());
    }

    public function test_the_last_unit_can_only_be_booked_once(): void
    {
        $offer = Offer::factory()->create(['available_units' => 1]);

        $this->reserve($offer, reference: 'web-order-first')->assertCreated();
        $this->reserve($offer, reference: 'web-order-second')->assertConflict();

        $this->assertSame(0, $offer->refresh()->available_units);
        $this->assertSame(1, Reservation::count());
    }

    public function test_a_sold_out_offer_cannot_be_booked(): void
    {
        $offer = Offer::factory()->soldOut()->create();

        $this->reserve($offer)->assertConflict();

        $this->assertSame(0, Reservation::count());
        $this->assertSame(0, $offer->refresh()->available_units);
    }

    public function test_an_expired_offer_cannot_be_booked(): void
    {
        $offer = Offer::factory()->expired()->create(['available_units' => 5]);

        $this->reserve($offer)->assertConflict();

        $this->assertSame(0, Reservation::count());
        $this->assertSame(5, $offer->refresh()->available_units);
    }

    public function test_a_repeated_reference_returns_the_original_reservation(): void
    {
        $offer = Offer::factory()->create(['available_units' => 3]);

        $first = $this->reserve($offer)->assertCreated();
        $second = $this->reserve($offer)->assertOk();

        $this->assertSame($first->json('data.id'), $second->json('data.id'));
        $this->assertSame(1, Reservation::count());
    }

    public function test_a_repeated_reference_does_not_take_a_second_unit(): void
    {
        $offer = Offer::factory()->create(['available_units' => 5]);

        $this->reserve($offer)->assertCreated();

        foreach (range(1, 4) as $ignored) {
            $this->reserve($offer)->assertOk();
        }

        $this->assertSame(4, $offer->refresh()->available_units);
        $this->assertSame(1, Reservation::count());
    }

    public function test_a_retry_of_the_booking_that_sold_the_offer_out_still_succeeds(): void
    {
        $offer = Offer::factory()->create(['available_units' => 1]);

        $first = $this->reserve($offer)->assertCreated();

        $retry = $this->reserve($offer)->assertOk();

        $this->assertSame($first->json('data.id'), $retry->json('data.id'));
        $this->assertSame(0, $offer->refresh()->available_units);
    }

    public function test_different_references_create_separate_reservations(): void
    {
        $offer = Offer::factory()->create(['available_units' => 3]);

        $this->reserve($offer, reference: 'web-order-1')->assertCreated();
        $this->reserve($offer, reference: 'web-order-2')->assertCreated();

        $this->assertSame(2, Reservation::count());
        $this->assertSame(1, $offer->refresh()->available_units);
    }

    public function test_the_same_reference_may_be_used_on_another_offer(): void
    {
        $first = Offer::factory()->create(['available_units' => 1]);
        $second = Offer::factory()->create(['available_units' => 1]);

        $this->reserve($first)->assertCreated();
        $this->reserve($second)->assertCreated();

        $this->assertSame(2, Reservation::count());
    }

    public function test_it_returns_404_for_an_unknown_offer(): void
    {
        $this->postJson('/api/offers/999/reservations', $this->payload())
            ->assertNotFound()
            ->assertJsonStructure(['message']);
    }

    public function test_it_rejects_an_incomplete_payload(): void
    {
        $offer = Offer::factory()->create(['available_units' => 3]);

        $this->postJson("/api/offers/{$offer->id}/reservations", [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['client_reference', 'customer_name', 'customer_email']);

        $this->assertSame(3, $offer->refresh()->available_units);
    }

    public function test_it_rejects_an_invalid_email(): void
    {
        $offer = Offer::factory()->create();

        $this->postJson("/api/offers/{$offer->id}/reservations", $this->payload(email: 'not-an-email'))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('customer_email');
    }

    private function reserve(Offer $offer, string $reference = 'web-order-9f782b1c'): TestResponse
    {
        return $this->postJson(
            "/api/offers/{$offer->id}/reservations",
            $this->payload(reference: $reference),
        );
    }

    /**
     * @return array<string, string>
     */
    private function payload(
        string $reference = 'web-order-9f782b1c',
        string $email = 'john@example.com',
    ): array {
        return [
            'client_reference' => $reference,
            'customer_name' => 'John Smith',
            'customer_email' => $email,
        ];
    }
}
