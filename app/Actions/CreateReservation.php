<?php

namespace App\Actions;

use App\Exceptions\OfferNotBookableException;
use App\Models\Offer;
use App\Models\Reservation;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

class CreateReservation
{
    /**
     * Reserve one unit of an offer.
     *
     * @param  array{client_reference: string, customer_name: string, customer_email: string}  $data
     * @return array{0: Reservation, 1: bool} the reservation and whether it was created now
     *
     * @throws OfferNotBookableException
     */
    public function __invoke(Offer $offer, array $data): array
    {
        $existing = $this->findExisting($offer, $data['client_reference']);

        if ($existing !== null) {
            return [$existing, false];
        }

        try {
            $reservation = DB::transaction(fn (): Reservation => $this->reserve($offer, $data));
        } catch (UniqueConstraintViolationException) {
            return [$this->findExisting($offer, $data['client_reference']), false];
        }

        return [$reservation, true];
    }

    /**
     * Take a unit and record the booking, or leave the inventory untouched.
     *
     * @param  array{client_reference: string, customer_name: string, customer_email: string}  $data
     *
     * @throws OfferNotBookableException
     */
    private function reserve(Offer $offer, array $data): Reservation
    {
        $taken = Offer::whereKey($offer->getKey())
            ->where('available_units', '>', 0)
            ->where('expires_at', '>', now())
            ->update(['available_units' => DB::raw('available_units - 1')]);

        if ($taken === 0) {
            throw new OfferNotBookableException;
        }

        return Reservation::create([
            'offer_id' => $offer->getKey(),
            'client_reference' => $data['client_reference'],
            'customer_name' => $data['customer_name'],
            'customer_email' => $data['customer_email'],
        ]);
    }

    private function findExisting(Offer $offer, string $clientReference): ?Reservation
    {
        return Reservation::where('offer_id', $offer->getKey())
            ->where('client_reference', $clientReference)
            ->first();
    }
}
