<?php

namespace Tests\Feature;

use App\Enums\ImportOfferError;
use App\Enums\ImportOfferStatus;
use App\Enums\ImportStatus;
use App\Jobs\ProcessImport;
use App\Models\Import;
use App\Models\ImportOffer;
use App\Models\Offer;
use App\Models\Property;
use App\Models\Supplier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;
use Throwable;

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
        $this->assertSame('Skipped 1 of 2 offers.', $import->error);
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

        (new ProcessImport($import->id))->failed(new RuntimeException('Supplier vanished.'));

        $import->refresh();
        $this->assertSame(ImportStatus::Failed, $import->status);
        $this->assertSame('Import failed.', $import->error);
        $this->assertNotNull($import->completed_at);
    }

    public function test_the_failed_hook_leaves_a_completed_import_alone(): void
    {
        $import = $this->import();
        $this->process($import, [$this->offer()]);

        (new ProcessImport($import->id))->failed(new RuntimeException('Too late.'));

        $this->assertSame(ImportStatus::Completed, $import->refresh()->status);
    }

    public function test_it_records_the_outcome_against_each_staged_offer(): void
    {
        $import = $this->import();

        $this->process($import, [
            $this->offer(externalId: 'offer-good'),
            $this->offer(externalId: 'offer-bad', currency: 'EUROS'),
        ]);

        $this->assertDatabaseHas('import_offers', [
            'import_id' => $import->id,
            'external_id' => 'offer-good',
            'status' => ImportOfferStatus::Applied->value,
            'error_code' => null,
            'error_message' => null,
        ]);

        $bad = ImportOffer::where('external_id', 'offer-bad')->sole();
        $this->assertSame(ImportOfferStatus::Skipped, $bad->status);
        $this->assertSame(ImportOfferError::InvalidData->value, $bad->error_code);
        $this->assertNotNull($bad->error_message);
    }

    public function test_a_rerun_leaves_offers_it_already_applied_alone(): void
    {
        $import = $this->import();
        $this->process($import, [$this->offer()]);

        $applied = ImportOffer::sole();
        $this->assertSame(ImportOfferStatus::Applied, $applied->status);

        $import->update(['status' => ImportStatus::Processing]);
        (new ProcessImport($import->id))->handle();

        $import->refresh();
        $this->assertSame(ImportStatus::Completed, $import->status);
        $this->assertSame(1, $import->processed_offers);
        $this->assertSame(1, Offer::count());
        $this->assertEquals($applied->updated_at, ImportOffer::sole()->updated_at);
    }

    public function test_a_rerun_after_a_partial_run_reports_the_whole_import(): void
    {
        $import = $this->import();

        $this->stage($import, [
            $this->offer(externalId: 'offer-1'),
            $this->offer(externalId: 'offer-2'),
        ]);

        ImportOffer::where('external_id', 'offer-1')->update([
            'status' => ImportOfferStatus::Applied,
        ]);
        ImportOffer::where('external_id', 'offer-2')->update([
            'status' => ImportOfferStatus::Skipped,
            'error_code' => 'unexpected_error',
            'error_message' => 'Worker vanished.',
        ]);

        $import->update(['status' => ImportStatus::Processing]);
        (new ProcessImport($import->id))->handle();

        $import->refresh();
        $this->assertSame(1, $import->processed_offers);
        $this->assertSame('Skipped 1 of 2 offers.', $import->error);
    }

    public function test_it_processes_an_import_larger_than_one_chunk(): void
    {
        $import = $this->import();

        $offers = [];

        for ($i = 1; $i <= 750; $i++) {
            $offers[] = $this->offer(
                externalId: 'offer-'.$i,
                propertyCode: 'BCN-'.str_pad((string) $i, 4, '0', STR_PAD_LEFT),
            );
        }

        $this->process($import, $offers);

        $import->refresh();
        $this->assertSame(ImportStatus::Completed, $import->status);
        $this->assertSame(750, $import->processed_offers);
        $this->assertSame(750, Offer::count());
        $this->assertSame(0, ImportOffer::where('status', ImportOfferStatus::Pending)->count());
    }

    public function test_a_failure_that_is_not_about_the_offer_stops_the_import(): void
    {
        $import = $this->import();

        ImportOffer::factory()->create([
            'import_id' => $import->id,
            'external_id' => 'offer-broken',
            'payload' => ['external_id' => 'offer-broken'],
        ]);

        $threw = false;

        try {
            (new ProcessImport($import->id))->handle();
        } catch (Throwable) {
            $threw = true;
        }

        $this->assertTrue($threw, 'The job should have let the failure through.');

        $this->assertSame(ImportOfferStatus::Pending, ImportOffer::sole()->status);
        $this->assertSame(ImportStatus::Processing, $import->refresh()->status);
    }

    public function test_the_failed_hook_does_not_repeat_the_exception(): void
    {
        $import = $this->import();

        (new ProcessImport($import->id))->failed(
            new RuntimeException('SQLSTATE[42S02]: insert into `offers` (`price`) values (1)'),
        );

        $this->assertSame('Import failed.', $import->refresh()->error);
    }

    /**
     * @param  array<int, array<string, mixed>>  $offers
     */
    private function process(Import $import, array $offers): void
    {
        $this->stage($import, $offers);

        (new ProcessImport($import->id))->handle();
    }

    /**
     * @param  array<int, array<string, mixed>>  $offers
     */
    private function stage(Import $import, array $offers): void
    {
        foreach ($offers as $offer) {
            ImportOffer::factory()->create([
                'import_id' => $import->id,
                'external_id' => $offer['external_id'],
                'payload' => $offer,
            ]);
        }
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
