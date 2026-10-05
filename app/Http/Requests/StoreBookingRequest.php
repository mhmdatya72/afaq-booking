<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreBookingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'hotel_id' => ['required', 'integer', 'exists:hotels,id'],
            'room_type_id' => ['required', 'integer', 'exists:room_types,id'],
            'check_in_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:today'],
            'check_out_date' => ['required', 'date_format:Y-m-d', 'after:check_in_date'],
            'rooms_count' => ['required', 'integer', 'min:1', 'max:20'],
            'customer_name' => ['required', 'string', 'max:255'],
            'customer_contact' => ['required', 'string', 'max:255'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            $key = $this->header('Idempotency-Key');
            if (! is_string($key) || trim($key) === '' || strlen($key) > 128) {
                $validator->errors()->add('Idempotency-Key', 'A non-empty Idempotency-Key header of at most 128 characters is required.');
            }
        }];
    }
}
