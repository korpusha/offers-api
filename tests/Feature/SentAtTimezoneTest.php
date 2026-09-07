<?php

namespace Tests\Feature;

use App\Models\Import;
use App\Models\Supplier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class SentAtTimezoneTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();

        Supplier::factory()->create(['code' => 'supplier-a']);
    }

    public function test_it_stores_an_offset_sent_at_as_utc(): void
    {
        $this->postJson('/api/imports', $this->payload(
            externalImportId: 'import-1',
            sentAt: '2026-09-07T12:00:00+03:00',
        ))->assertAccepted();

        $this->assertSame(
            '2026-09-07 09:00:00',
            Import::sole()->sent_at->utc()->toDateTimeString(),
        );
    }

    public function test_a_later_utc_import_is_not_mistaken_for_stale(): void
    {
        $this->postJson('/api/imports', $this->payload(
            externalImportId: 'import-1',
            sentAt: '2026-09-07T12:00:00+03:00',
        ))->assertAccepted();

        $this->postJson('/api/imports', $this->payload(
            externalImportId: 'import-2',
            sentAt: '2026-09-07T10:00:00Z',
        ))->assertAccepted();

        $this->assertSame(2, Import::count());
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(string $externalImportId, string $sentAt): array
    {
        return [
            'supplier' => 'supplier-a',
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
