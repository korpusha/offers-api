<?php

namespace Database\Seeders;

use App\Models\Supplier;
use Illuminate\Database\Seeder;

class SupplierSeeder extends Seeder
{
    /**
     * The suppliers the API accepts imports from.
     *
     * @var array<int, array{code: string, name: string}>
     */
    private const SUPPLIERS = [
        ['code' => 'supplier-a', 'name' => 'Supplier A'],
        ['code' => 'supplier-b', 'name' => 'Supplier B'],
    ];

    /**
     * Seed the suppliers table.
     */
    public function run(): void
    {
        foreach (self::SUPPLIERS as $supplier) {
            Supplier::updateOrCreate(
                ['code' => $supplier['code']],
                ['name' => $supplier['name']],
            );
        }
    }
}
