<?php

namespace App\Http\Requests;

use App\Support\CurrencyConverter;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SubscriptionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'currency' => ['required', Rule::in(CurrencyConverter::supportedCurrencies())],
            'billing_cycle' => ['required', Rule::in(['monthly', 'yearly'])],
            'next_renewal_date' => ['required', 'date'],
            'status' => ['required', Rule::in(['active', 'pending_cancel', 'cancelled'])],
            'cancel_url' => ['nullable', 'url', 'max:2048'],
            'notes' => ['nullable', 'string'],
        ];
    }
}
