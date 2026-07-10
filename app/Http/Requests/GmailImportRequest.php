<?php

namespace App\Http\Requests;

use App\Support\CurrencyConverter;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class GmailImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'items' => ['required', 'array'],
            'items.*.action' => ['required', Rule::in(['create', 'update_status'])],
            'items.*.duplicate_of' => ['nullable', 'integer'],

            // Required only for create rows.
            'items.*.provider_key' => ['nullable', 'string', 'max:255'],
            'items.*.name' => ['required_if:items.*.action,create', 'string', 'max:255'],
            'items.*.amount' => ['required_if:items.*.action,create', 'nullable', 'numeric', 'gt:0'],
            'items.*.currency' => ['required_if:items.*.action,create', Rule::in(CurrencyConverter::supportedCurrencies())],
            'items.*.billing_cycle' => ['required_if:items.*.action,create', Rule::in(['monthly', 'yearly'])],
            'items.*.next_renewal_date' => ['required_if:items.*.action,create', 'nullable', 'date'],
            'items.*.cancel_url' => ['nullable', 'url', 'max:2048'],
        ];
    }
}
