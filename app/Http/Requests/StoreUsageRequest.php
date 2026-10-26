<?php

namespace App\Http\Requests;

use App\Http\Middleware\AuthenticateMerchant;
use App\Models\Merchant;
use Illuminate\Foundation\Http\FormRequest;

class StoreUsageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * The idempotency key may be sent as the standard `Idempotency-Key` header (preferred)
     * or as a body field, for clients that cannot set custom headers.
     */
    protected function prepareForValidation(): void
    {
        $this->merge([
            'idempotency_key' => $this->header('Idempotency-Key', $this->input('idempotency_key')),
            'usage_date' => $this->input('usage_date', now()->toDateString()),
        ]);
    }

    public function rules(): array
    {
        $lateDays = config('billing.usage.late_arrival_days');

        return [
            'idempotency_key' => ['required', 'string', 'min:8', 'max:64', 'regex:/^[A-Za-z0-9_\-:.]+$/'],
            'customer_id' => ['required', 'string', 'max:100'],
            'quantity' => ['required', 'integer', 'min:1', 'max:'.config('billing.usage.max_quantity')],
            'usage_date' => [
                'required',
                'date_format:Y-m-d',
                'before_or_equal:'.now()->toDateString(),
                'after_or_equal:'.now()->subDays($lateDays)->toDateString(),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'usage_date.after_or_equal' => 'usage_date is outside the '.config('billing.usage.late_arrival_days').'-day late-arrival window.',
        ];
    }

    public function merchant(): Merchant
    {
        return $this->attributes->get(AuthenticateMerchant::ATTRIBUTE);
    }
}
