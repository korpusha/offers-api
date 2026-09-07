<?php

namespace Tests\Feature;

use App\Enums\ImportOfferError;
use App\Enums\ImportStatus;
use App\Models\Import;
use App\Models\ImportOffer;
use App\Models\Supplier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShowImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_reports_a_completed_import(): void
    {
        $supplier = Supplier::factory()->create(['code' => 'supplier-a']);

        $import = Import::factory()->completed(totalOffers: 20)->create([
            'supplier_id' => $supplier->id,
            'external_import_id' => 'import-2026-09-01-001',
            'sent_at' => '2026-09-01T10:00:00Z',
        ]);

        $this->getJson("/api/imports/{$import->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $import->id)
            ->assertJsonPath('data.supplier', 'supplier-a')
            ->assertJsonPath('data.external_import_id', 'import-2026-09-01-001')
            ->assertJsonPath('data.sent_at', '2026-09-01T10:00:00Z')
            ->assertJsonPath('data.status', ImportStatus::Completed->value)
            ->assertJsonPath('data.total_offers', 20)
            ->assertJsonPath('data.processed_offers', 20)
            ->assertJsonPath('data.error', null)
            ->assertJsonPath('data.skipped', []);
    }

    public function test_a_pending_import_has_no_completion_details(): void
    {
        $import = Import::factory()->create();

        $this->getJson("/api/imports/{$import->id}")
            ->assertOk()
            ->assertJsonPath('data.status', ImportStatus::Pending->value)
            ->assertJsonPath('data.processed_offers', 0)
            ->assertJsonPath('data.completed_at', null)
            ->assertJsonPath('data.error', null);
    }

    public function test_it_reports_a_partially_processed_import(): void
    {
        $import = Import::factory()->completed(totalOffers: 20, processedOffers: 18)->create([
            'error' => 'Skipped 2 of 20 offers.',
        ]);

        ImportOffer::factory()->for($import)->applied()->create();
        ImportOffer::factory()->for($import)->skipped(
            ImportOfferError::InvalidData->value,
            "SQLSTATE[22001]: Data too long for column 'currency'",
        )->create(['external_id' => 'offer-a-3']);

        $response = $this->getJson("/api/imports/{$import->id}")
            ->assertOk()
            ->assertJsonPath('data.status', ImportStatus::Completed->value)
            ->assertJsonPath('data.total_offers', 20)
            ->assertJsonPath('data.processed_offers', 18)
            ->assertJsonPath('data.error', 'Skipped 2 of 20 offers.')
            ->assertJsonPath('data.skipped', [
                ['external_id' => 'offer-a-3', 'code' => ImportOfferError::InvalidData->value],
            ]);

        $this->assertStringNotContainsString('SQLSTATE', $response->getContent());
    }

    public function test_it_names_at_most_fifty_skipped_offers(): void
    {
        $import = Import::factory()->completed(totalOffers: 60, processedOffers: 0)->create([
            'error' => 'Skipped 60 of 60 offers.',
        ]);

        ImportOffer::factory()->for($import)->skipped()->count(60)->create();

        $this->getJson("/api/imports/{$import->id}")
            ->assertOk()
            ->assertJsonCount(50, 'data.skipped')
            ->assertJsonPath('data.error', 'Skipped 60 of 60 offers.');
    }

    public function test_a_pending_offer_is_not_reported_as_skipped(): void
    {
        $import = Import::factory()->create();

        ImportOffer::factory()->for($import)->create();

        $this->getJson("/api/imports/{$import->id}")
            ->assertOk()
            ->assertJsonPath('data.skipped', []);
    }

    public function test_it_reports_a_failed_import(): void
    {
        $import = Import::factory()->failed()->create();

        $this->getJson("/api/imports/{$import->id}")
            ->assertOk()
            ->assertJsonPath('data.status', ImportStatus::Failed->value)
            ->assertJsonPath('data.error', 'Import failed.');
    }

    public function test_it_returns_the_expected_fields_only(): void
    {
        $import = Import::factory()->create();

        $this->getJson("/api/imports/{$import->id}")
            ->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'id', 'supplier', 'external_import_id', 'sent_at', 'status',
                    'total_offers', 'processed_offers', 'error', 'skipped',
                    'created_at', 'completed_at',
                ],
            ])
            ->assertJsonCount(11, 'data');
    }

    public function test_it_returns_404_for_an_unknown_import(): void
    {
        $this->getJson('/api/imports/999')
            ->assertNotFound()
            ->assertJsonStructure(['message']);
    }

    public function test_the_status_endpoint_follows_the_import_through_the_queue(): void
    {
        Supplier::factory()->create(['code' => 'supplier-a']);

        $created = $this->postJson('/api/imports', [
            'supplier' => 'supplier-a',
            'external_import_id' => 'import-2026-09-01-001',
            'sent_at' => '2026-09-01T10:00:00Z',
            'offers' => [
                [
                    'external_id' => 'offer-a-10001',
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
            ],
        ])->assertAccepted();

        $this->getJson("/api/imports/{$created->json('data.id')}")
            ->assertOk()
            ->assertJsonPath('data.status', ImportStatus::Completed->value)
            ->assertJsonPath('data.total_offers', 1)
            ->assertJsonPath('data.processed_offers', 1);
    }
}
