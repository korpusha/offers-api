<?php

namespace App\Actions;

use App\Models\Property;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

class SearchProperties
{
    /**
     * @param  array{check_in: string, check_out: string, guests: int, city?: string|null}  $criteria
     * @return LengthAwarePaginator<int, Property>
     */
    public function __invoke(array $criteria, int $perPage): LengthAwarePaginator
    {
        return Property::query()
            ->joinSub(
                $this->rankedOffers($criteria),
                'best',
                fn ($join) => $join
                    ->on('best.property_id', '=', 'properties.id')
                    ->where('best.rn', '=', 1),
            )
            ->join('suppliers', 'suppliers.id', '=', 'best.supplier_id')
            ->when(
                filled($criteria['city'] ?? null),
                fn ($query) => $query->where('properties.city', $criteria['city']),
            )
            ->select([
                'properties.id',
                'properties.code',
                'properties.name',
                'properties.city',
                'best.id as best_offer_id',
                'best.price as best_offer_price',
                'best.currency as best_offer_currency',
                'best.available_units as best_offer_available_units',
                'best.expires_at as best_offer_expires_at',
                'suppliers.code as best_offer_supplier',
            ])
            ->orderBy('best.price')
            ->orderBy('properties.id')
            ->paginate($perPage)
            ->withQueryString();
    }

    /**
     * @param  array{check_in: string, check_out: string, guests: int, city?: string|null}  $criteria
     */
    private function rankedOffers(array $criteria): QueryBuilder
    {
        return DB::table('offers')
            ->select('offers.*')
            ->selectRaw(
                'ROW_NUMBER() OVER (PARTITION BY property_id ORDER BY price ASC, id ASC) AS rn'
            )
            ->where('check_in', '=', $criteria['check_in'])
            ->where('check_out', '=', $criteria['check_out'])
            ->where('max_guests', '>=', $criteria['guests'])
            ->where('available_units', '>', 0)
            ->where('expires_at', '>', now());
    }
}
