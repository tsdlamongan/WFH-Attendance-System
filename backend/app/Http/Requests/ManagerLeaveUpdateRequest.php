<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ManagerLeaveUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var \App\Models\User|null $user */
        $user = $this->user();

        return $user !== null && $user->isManager();
    }

    public function rules(): array
    {
        return [
            'start_date' => 'sometimes|date',
            'end_date' => 'sometimes|date|after_or_equal:start_date',
            'created_at' => 'sometimes|date',
            'approved_at' => 'sometimes|nullable|date',
        ];
    }

    public function messages(): array
    {
        return [
            'start_date.date' => 'Tanggal mulai harus berupa tanggal yang valid.',
            'end_date.date' => 'Tanggal selesai harus berupa tanggal yang valid.',
            'end_date.after_or_equal' => 'Tanggal selesai harus sama atau setelah tanggal mulai.',
            'created_at.date' => 'Tanggal pengajuan harus berupa tanggal yang valid.',
            'approved_at.date' => 'Tanggal disetujui/ditolak harus berupa tanggal yang valid.',
        ];
    }
}
