<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AttendanceEditRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return auth()->check() && (auth()->user()->isManager() || auth()->user()->isSuperAdmin());
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'date' => 'required|date',
            'check_in' => 'required|date',
            'check_out' => 'nullable|date|after:check_in',
            'reason' => 'required|string|min:10',
        ];
    }

    public function messages(): array
    {
        return [
            'date.required' => 'Date is required.',
            'date.date' => 'Date must be a valid date.',
            'check_in.required' => 'Check-in time is required.',
            'check_out.after' => 'Check-out time must be after check-in time.',
            'reason.required' => 'Reason is required for audit purposes.',
            'reason.min' => 'Reason must be at least 10 characters.',
        ];
    }

    /**
     * Configure the validator instance.
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            // Ensure check_in date matches the date field
            $date = $this->input('date');
            $checkIn = $this->input('check_in');
            $checkOut = $this->input('check_out');

            if ($date && $checkIn) {
                $dateOnly = \Carbon\Carbon::parse($date)->toDateString();
                $checkInDate = \Carbon\Carbon::parse($checkIn)->toDateString();

                if ($dateOnly !== $checkInDate) {
                    $validator->errors()->add(
                        'check_in',
                        'Check-in date must match the attendance date.'
                    );
                }
            }

            // Ensure check_out date matches the date field if provided
            if ($date && $checkOut) {
                $dateOnly = \Carbon\Carbon::parse($date)->toDateString();
                $checkOutDate = \Carbon\Carbon::parse($checkOut)->toDateString();

                if ($dateOnly !== $checkOutDate) {
                    $validator->errors()->add(
                        'check_out',
                        'Check-out date must match the attendance date.'
                    );
                }
            }
        });
    }
}
