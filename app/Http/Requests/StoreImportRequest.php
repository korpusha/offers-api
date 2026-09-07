<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreImportRequest extends FormRequest
{
    /**
     * The most offers one import may carry.
     *
     * Larger catalogues are split across several imports, each with its own
     * external_import_id and a later sent_at.
     */
    public const MAX_OFFERS = 5000;

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'supplier' => ['required', 'string', Rule::exists('suppliers', 'code')],
            'external_import_id' => ['required', 'string', 'max:128'],
            'sent_at' => ['required', 'date'],

            'offers' => ['required', 'array', 'min:1', 'max:'.self::MAX_OFFERS],
            'offers.*.external_id' => ['required', 'string', 'max:128'],

            'offers.*.property' => ['required', 'array'],
            'offers.*.property.code' => ['required', 'string', 'max:64'],
            'offers.*.property.name' => ['required', 'string', 'max:255'],
            'offers.*.property.city' => ['required', 'string', 'max:120'],

            'offers.*.check_in' => ['required', 'date_format:Y-m-d'],
            'offers.*.check_out' => ['required', 'date_format:Y-m-d', 'after:offers.*.check_in'],
            'offers.*.max_guests' => ['required', 'integer', 'min:1'],
            'offers.*.price' => ['required', 'integer', 'min:0'],
            'offers.*.currency' => ['required', 'string', 'size:3'],
            'offers.*.available_units' => ['required', 'integer', 'min:0'],
            'offers.*.expires_at' => ['required', 'date'],
        ];
    }
}
