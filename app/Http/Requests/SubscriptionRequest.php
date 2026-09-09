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

    protected function prepareForValidation(): void
    {
        $this->merge([
            'list' => $this->input('list', 'personal'),
        ]);
    }

    /**
     * @return array<string,string>
     */
    public function messages(): array
    {
        return [
            'started_at.required_with' => 'Cần ngày bắt đầu để tính được kỳ kết thúc.',
        ];
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
            'list' => ['required', Rule::in(['personal', 'business', 'family'])],
            'category' => ['nullable', 'string', 'max:50'],
            'payment_method_id' => [
                'nullable',
                Rule::exists('payment_methods', 'id')->where(
                    fn ($query) => $query->where('user_id', $this->user()->id),
                ),
            ],
            'is_trial' => ['boolean'],
            'started_at' => ['nullable', 'date', 'required_with:total_periods'],
            'total_periods' => ['nullable', 'integer', 'min:1', 'max:600'],
        ];
    }
}
