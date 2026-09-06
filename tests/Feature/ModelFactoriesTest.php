<?php

namespace Tests\Feature;

use App\Enums\ImportStatus;
use App\Models\Import;
use App\Models\Offer;
use App\Models\Property;
use App\Models\Reservation;
use App\Models\Supplier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ModelFactoriesTest extends TestCase
{
    use RefreshDatabase;

    public function test_offer_factory_creates_its_relations(): void
    {
        $offer = Offer::factory()->create();

        $this->assertInstanceOf(Supplier::class, $offer->supplier);
        $this->assertInstanceOf(Property::class, $offer->property);
        $this->assertNull($offer->lastImport);
    }

    public function test_offer_casts_dates_and_integers(): void
    {
        $offer = Offer::factory()->create()->fresh();

        $this->assertInstanceOf(Carbon::class, $offer->check_in);
        $this->assertInstanceOf(Carbon::class, $offer->expires_at);
        $this->assertInstanceOf(Carbon::class, $offer->source_sent_at);
        $this->assertIsInt($offer->price);
        $this->assertIsInt($offer->available_units);
    }

    public function test_offer_states_produce_unbookable_offers(): void
    {
        $this->assertTrue(Offer::factory()->expired()->create()->expires_at->isPast());
        $this->assertSame(0, Offer::factory()->soldOut()->create()->available_units);
    }

    public function test_offer_for_stay_state_sets_exact_dates(): void
    {
        $offer = Offer::factory()->forStay('2026-10-10', '2026-10-15')->create()->fresh();

        $this->assertSame('2026-10-10', $offer->check_in->toDateString());
        $this->assertSame('2026-10-15', $offer->check_out->toDateString());
    }

    public function test_import_casts_status_to_enum(): void
    {
        $import = Import::factory()->create()->fresh();

        $this->assertSame(ImportStatus::Pending, $import->status);
        $this->assertInstanceOf(Supplier::class, $import->supplier);
    }

    public function test_import_completed_state_allows_partial_success(): void
    {
        $import = Import::factory()->completed(totalOffers: 20, processedOffers: 18)->create();

        $this->assertSame(ImportStatus::Completed, $import->status);
        $this->assertSame(20, $import->total_offers);
        $this->assertSame(18, $import->processed_offers);
        $this->assertNotNull($import->completed_at);
    }

    public function test_reservation_belongs_to_an_offer(): void
    {
        $reservation = Reservation::factory()->create();

        $this->assertInstanceOf(Offer::class, $reservation->offer);
        $this->assertTrue($reservation->offer->reservations->contains($reservation));
    }

    public function test_property_in_city_state_matches_the_requested_city(): void
    {
        $property = Property::factory()->inCity('Barcelona')->create();

        $this->assertSame('Barcelona', $property->city);
        $this->assertStringStartsWith('BAR-', $property->code);
    }
}
