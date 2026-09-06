<?php

namespace App\Http\Controllers\Api;

use App\Actions\SearchProperties;
use App\Http\Controllers\Controller;
use App\Http\Requests\SearchPropertiesRequest;
use App\Http\Resources\PropertyResource;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class PropertyController extends Controller
{
    private const PER_PAGE = 15;

    /**
     * Search for properties with a bookable offer for the requested stay.
     */
    public function index(
        SearchPropertiesRequest $request,
        SearchProperties $searchProperties,
    ): AnonymousResourceCollection {
        $properties = $searchProperties($request->validated(), self::PER_PAGE);

        return PropertyResource::collection($properties);
    }
}
