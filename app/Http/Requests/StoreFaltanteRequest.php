<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreFaltanteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'descripcion' => 'required|string|max:255',
            'pedido'      => 'required|in:GENERAL,TRUPER',
            'confirmado'  => 'boolean',
        ];
    }
}
