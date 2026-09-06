<?php

namespace Tests\Feature;

use App\Enums\ImportStatus;
use App\Jobs\ProcessImport;
use App\Models\Import;
use App\Models\Offer;
use App\Models\Property;
use App\Models\Supplier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class ProcessImportTest extends TestCase
{
    use RefreshDatabase;

    private Supplier $supplier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->supplier = Supplier::factory()->create(['code' => 'supplier-a']);
    }

    public function test_it_creates_the_property_and_the_offer(): void
    {
        $import = $this->import(sentAt: '2026-09-01T10:00:00Z');

        $this->process($import, [$this->offer()]);

        $this->assertDatabaseHas('properties', [
            'code' => 'BCN-0001',
            'name' => 'Apartment near Sagrada Familia',
            'city' => 'Barcelona',
        ]);

        $offer = Offer::sole();
        $this->assertSame('offer-a-10001', $offer->external_id);
        $this->assertSame($this->supplier->id, $offer->supplier_id);
        $this->assertSame(72500, $offer->price);
        $this->assertSame(2, $offer->available_units);
        $this->assertSame('2026-10-10', $offer->check_in->toDateString());
        $this->assertSame($import->id, $offer->last_import_id);
    }

    public function test_it_completes_the_import_and_counts_the_offers(): void
    {
        $import = $this->import();

        $this->process($import, [
            $this->offer(externalId: 'offer-1'),
            $this->offer(externalId: 'offer-2', propertyCode: 'BCN-0002'),
        ]);

        $import->refresh();
        $this->assertSame(ImportStatus::Completed, $import->status);
        $this->assertSame(2, $import->processed_offers);
        $this->assertNull($import->error);
        $this->assertNotNull($import->completed_at);
    }

    public function test_it_reuses_an_existing_property_by_code(): void
    {
        Property::factory()->create(['code' => 'BCN-0001', 'city' => 'Barcelona']);

        $this->process($this->import(), [$this->offer()]);

        $this->assertSame(1, Property::count());
    }

    public function test_a_newer_import_updates_an_existing_offer(): void
    {
        $this->process(
            $this->import(externalImportId: 'import-1', sentAt: '2026-09-01T10:00:00Z'),
            [$this->offer(price: 72500, availableUnits: 2)],
        );

        $this->process(
            $this->import(externalImportId: 'import-2', sentAt: '2026-09-02T10:00:00Z'),
            [$this->offer(price: 60000, availableUnits: 5)],
        );

        $offer = Offer::sole();
        $this->assertSame(60000, $offer->price);
        $this->assertSame(5, $offer->available_units);
    }

    public function test_an_older_import_does_not_overwrite_a_newer_offer(): void
    {
        $this->process(
            $this->import(externalImportId: 'import-newer', sentAt: '2026-09-02T10:00:00Z'),
            [$this->offer(price: 60000, availableUnits: 5)],
        );

        $this->process(
            $this->import(externalImportId: 'import-older', sentAt: '2026-09-01T10:00:00Z'),
            [$this->offer(price: 72500, availableUnits: 2)],
        );

        $offer = Offer::sole();
        $this->assertSame(60000, $offer->price);
        $this->assertSame(5, $offer->available_units);
        $this->assertSame('2026-09-02 10:00:00', $offer->source_sent_at->toDateTimeString());
    }

    public function test_the_same_external_id_from_another_supplier_is_a_separate_offer(): void
    {
        $other = Supplier::factory()->create(['code' => 'supplier-b']);

        $this->process($this->import(), [$this->offer()]);
        $this->process($this->import(supplier: $other, externalImportId: 'import-b'), [$this->offer()]);

        $this->assertSame(2, Offer::count());
    }

    public function test_a_failing_offer_is_skipped_and_the_import_still_completes(): void
    {
        $import = $this->import();

        $this->process($import, [
            $this->offer(externalId: 'offer-good'),
            $this->offer(externalId: 'offer-bad', currency: 'EUROS'),
        ]);

        $import->refresh();
        $this->assertSame(ImportStatus::Completed, $import->status);
        $this->assertSame(1, $import->processed_offers);
        $this->assertStringContainsString('Skipped 1 of 2 offers', (string) $import->error);
        $this->assertStringContainsString('offer-bad', (string) $import->error);
        $this->assertSame(1, Offer::count());
    }

    public function test_it_moves_the_import_through_processing_to_completed(): void
    {
        $import = $this->import();
        $this->assertSame(ImportStatus::Pending, $import->status);

        $this->process($import, [$this->offer()]);

        $this->assertSame(ImportStatus::Completed, $import->refresh()->status);
    }

    public function test_it_does_not_reprocess_an_import_that_already_completed(): void
    {
        $import = $this->import();
        $offers = [$this->offer()];

        $this->process($import, $offers);
        $completedAt = $import->refresh()->completed_at;

        $this->process($import, [$this->offer(price: 1)]);

        $this->assertSame(72500, Offer::sole()->price);
        $this->assertEquals($completedAt, $import->refresh()->completed_at);
    }

    public function test_rerunning_the_job_after_a_crash_finishes_the_import(): void
    {
        $import = $this->import();
        $import->update(['status' => ImportStatus::Processing]);

        $this->process($import, [$this->offer()]);

        $this->assertSame(ImportStatus::Completed, $import->refresh()->status);
        $this->assertSame(1, Offer::count());
    }

    public function test_the_failed_hook_marks_the_import_failed(): void
    {
        $import = $this->import();

        (new ProcessImport($import->id, []))->failed(new RuntimeException('Supplier vanished.'));

        $import->refresh();
        $this->assertSame(ImportStatus::Failed, $import->status);
        $this->assertSame('Supplier vanished.', $import->error);
        $this->assertNotNull($import->completed_at);
    }

    public function test_the_failed_hook_leaves_a_completed_import_alone(): void
    {
        $import = $this->import();
        $this->process($import, [$this->offer()]);

        (new ProcessImport($import->id, []))->failed(new RuntimeException('Too late.'));

        $this->assertSame(ImportStatus::Completed, $import->refresh()->status);
    }

    /**
     * @param  array<int, array<string, mixed>>  $offers
     */
    private function process(Import $import, array $offers): void
    {
        (new ProcessImport($import->id, $offers))->handle();
    }

    private function import(
        ?Supplier $supplier = null,
        string $externalImportId = 'import-2026-09-01-001',
        string $sentAt = '2026-09-01T10:00:00Z',
    ): Import {
        return Import::factory()->create([
            'supplier_id' => ($supplier ?? $this->supplier)->id,
            'external_import_id' => $externalImportId,
            'sent_at' => $sentAt,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function offer(
        string $externalId = 'offer-a-10001',
        string $propertyCode = 'BCN-0001',
        int $price = 72500,
        int $availableUnits = 2,
        string $currency = 'EUR',
    ): array {
        return [
            'external_id' => $externalId,
            'property' => [
                'code' => $propertyCode,
                'name' => 'Apartment near Sagrada Familia',
                'city' => 'Barcelona',
            ],
            'check_in' => '2026-10-10',
            'check_out' => '2026-10-15',
            'max_guests' => 4,
            'price' => $price,
            'currency' => $currency,
            'available_units' => $availableUnits,
            'expires_at' => '2026-09-10T23:59:59Z',
        ];
    }
}
