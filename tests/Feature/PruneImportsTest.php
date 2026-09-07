<?php

namespace Tests\Feature;

use App\Models\Import;
use App\Models\ImportOffer;
use App\Models\Offer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PruneImportsTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_prunes_imports_past_the_retention_window(): void
    {
        config(['imports.retention_days' => 90]);

        $old = $this->import(daysAgo: 91);
        $recent = $this->import(daysAgo: 89);

        $this->prune();

        $this->assertNull(Import::find($old->id));
        $this->assertNotNull(Import::find($recent->id));
    }

    public function test_pruning_an_import_takes_its_staged_offers_with_it(): void
    {
        config(['imports.retention_days' => 90]);

        $old = ImportOffer::factory()->for($this->import(daysAgo: 91))->create();
        $recent = ImportOffer::factory()->for($this->import(daysAgo: 1))->create();

        $this->prune();

        $this->assertNull(ImportOffer::find($old->id));
        $this->assertNotNull(ImportOffer::find($recent->id));
    }

    public function test_a_pruned_import_leaves_the_catalogue_standing(): void
    {
        config(['imports.retention_days' => 90]);

        $import = $this->import(daysAgo: 91);
        $offer = Offer::factory()->create([
            'last_import_id' => $import->id,
            'price' => 72500,
        ]);

        $this->prune();

        $offer->refresh();
        $this->assertSame(72500, $offer->price);
        $this->assertNull($offer->last_import_id);
    }

    private function import(int $daysAgo): Import
    {
        return Import::factory()->create([
            'created_at' => now()->subDays($daysAgo),
        ]);
    }

    private function prune(): void
    {
        $this->artisan('model:prune', ['--model' => [Import::class]])
            ->assertSuccessful();
    }
}
