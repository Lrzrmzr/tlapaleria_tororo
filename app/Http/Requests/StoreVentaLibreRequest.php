<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreVentaLibreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'descripcion'     => 'required|string|max:255',
            'total'           => 'required|numeric|min:0.01',
            'idempotency_key' => 'nullable|string|max:64',
        ];
    }
}
