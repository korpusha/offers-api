<?php

namespace App\Http\Controllers\Api;

use App\Actions\RegisterImport;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreImportRequest;
use App\Http\Resources\ImportAcceptedResource;
use App\Http\Resources\ImportResource;
use App\Models\Import;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

class ImportController extends Controller
{
    /**
     * Accept an import from a supplier and queue it for processing.
     */
    public function store(StoreImportRequest $request, RegisterImport $registerImport): JsonResponse
    {
        $import = $registerImport($request->validated());

        return ImportAcceptedResource::make($import)
            ->response()
            ->setStatusCode(Response::HTTP_ACCEPTED);
    }

    /**
     * Report where an import currently stands.
     */
    public function show(Import $import): ImportResource
    {
        return ImportResource::make($import->load('supplier'));
    }
}
