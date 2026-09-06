<?php

namespace Tests\Feature;

use App\Models\Supplier;
use Database\Seeders\SupplierSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SupplierSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_seeds_both_suppliers(): void
    {
        $this->seed(SupplierSeeder::class);

        $this->assertSame(2, Supplier::count());
        $this->assertDatabaseHas('suppliers', ['code' => 'supplier-a']);
        $this->assertDatabaseHas('suppliers', ['code' => 'supplier-b']);
    }

    public function test_running_it_twice_does_not_duplicate_suppliers(): void
    {
        $this->seed(SupplierSeeder::class);
        $originalId = Supplier::where('code', 'supplier-a')->value('id');

        $this->seed(SupplierSeeder::class);

        $this->assertSame(2, Supplier::count());
        $this->assertSame($originalId, Supplier::where('code', 'supplier-a')->value('id'));
    }
}
