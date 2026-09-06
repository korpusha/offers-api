<?php

namespace App\Http\Controllers\Api;

use App\Actions\CreateReservation;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreReservationRequest;
use App\Http\Resources\ReservationResource;
use App\Models\Offer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

class ReservationController extends Controller
{
    /**
     * Reserve a unit of an offer.
     */
    public function store(
        StoreReservationRequest $request,
        Offer $offer,
        CreateReservation $createReservation,
    ): JsonResponse {
        [$reservation, $created] = $createReservation($offer, $request->validated());

        return ReservationResource::make($reservation)
            ->response()
            ->setStatusCode($created ? Response::HTTP_CREATED : Response::HTTP_OK);
    }
}
