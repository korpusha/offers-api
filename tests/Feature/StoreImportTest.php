<?php

namespace Tests\Feature;

use App\Enums\ImportStatus;
use App\Jobs\ProcessImport;
use App\Models\Import;
use App\Models\Supplier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class StoreImportTest extends TestCase
{
    use RefreshDatabase;

    private Supplier $supplier;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();

        $this->supplier = Supplier::factory()->create(['code' => 'supplier-a']);
    }

    public function test_it_accepts_an_import_and_queues_it(): void
    {
        $response = $this->postJson('/api/imports', $this->payload());

        $response->assertAccepted()
            ->assertJsonStructure(['data' => ['id', 'status']])
            ->assertJsonPath('data.status', ImportStatus::Pending->value);

        $this->assertDatabaseHas('imports', [
            'supplier_id' => $this->supplier->id,
            'external_import_id' => 'import-2026-09-01-001',
            'status' => ImportStatus::Pending->value,
            'total_offers' => 1,
            'processed_offers' => 0,
        ]);

        Queue::assertPushed(ProcessImport::class, 1);
    }

    public function test_resending_the_same_import_neither_duplicates_nor_requeues(): void
    {
        $first = $this->postJson('/api/imports', $this->payload());
        $second = $this->postJson('/api/imports', $this->payload());

        $second->assertAccepted()
            ->assertJsonPath('data.id', $first->json('data.id'));

        $this->assertSame(1, Import::count());
        Queue::assertPushed(ProcessImport::class, 1);
    }

    public function test_a_resend_reports_the_current_status_not_pending(): void
    {
        $this->postJson('/api/imports', $this->payload());

        Import::query()->update(['status' => ImportStatus::Completed]);

        $this->postJson('/api/imports', $this->payload())
            ->assertAccepted()
            ->assertJsonPath('data.status', ImportStatus::Completed->value);
    }

    public function test_it_rejects_an_import_older_than_the_latest_one(): void
    {
        $this->postJson('/api/imports', $this->payload(
            externalImportId: 'import-newer',
            sentAt: '2026-09-01T12:00:00Z',
        ))->assertAccepted();

        $this->postJson('/api/imports', $this->payload(
            externalImportId: 'import-older',
            sentAt: '2026-09-01T10:00:00Z',
        ))->assertConflict();

        $this->assertSame(1, Import::count());
        Queue::assertPushed(ProcessImport::class, 1);
    }

    public function test_it_rejects_an_import_with_the_same_sent_at(): void
    {
        $this->postJson('/api/imports', $this->payload(externalImportId: 'import-first'))
            ->assertAccepted();

        $this->postJson('/api/imports', $this->payload(externalImportId: 'import-second'))
            ->assertConflict();
    }

    public function test_a_resend_is_not_mistaken_for_a_stale_import(): void
    {
        $this->postJson('/api/imports', $this->payload())->assertAccepted();

        $this->postJson('/api/imports', $this->payload())->assertAccepted();
    }

    public function test_each_supplier_has_its_own_freshness_watermark(): void
    {
        Supplier::factory()->create(['code' => 'supplier-b']);

        $this->postJson('/api/imports', $this->payload(sentAt: '2026-09-01T12:00:00Z'))
            ->assertAccepted();

        $this->postJson('/api/imports', $this->payload(
            supplier: 'supplier-b',
            sentAt: '2026-09-01T10:00:00Z',
        ))->assertAccepted();

        $this->assertSame(2, Import::count());
    }

    public function test_it_rejects_an_unknown_supplier(): void
    {
        $this->postJson('/api/imports', $this->payload(supplier: 'supplier-zzz'))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('supplier');

        Queue::assertNothingPushed();
    }

    public function test_it_rejects_a_payload_without_offers(): void
    {
        $payload = $this->payload();
        $payload['offers'] = [];

        $this->postJson('/api/imports', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('offers');
    }

    public function test_it_rejects_a_checkout_that_is_not_after_checkin(): void
    {
        $payload = $this->payload();
        $payload['offers'][0]['check_out'] = $payload['offers'][0]['check_in'];

        $this->postJson('/api/imports', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('offers.0.check_out');
    }

    public function test_it_rejects_a_non_integer_price(): void
    {
        $payload = $this->payload();
        $payload['offers'][0]['price'] = 725.55;

        $this->postJson('/api/imports', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('offers.0.price');
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(
        string $supplier = 'supplier-a',
        string $externalImportId = 'import-2026-09-01-001',
        string $sentAt = '2026-09-01T10:00:00Z',
    ): array {
        return [
            'supplier' => $supplier,
            'external_import_id' => $externalImportId,
            'sent_at' => $sentAt,
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
        ];
    }
}
