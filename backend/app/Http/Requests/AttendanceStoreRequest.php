<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

class AttendanceStoreRequest extends FormRequest
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
            'user_id' => 'required|integer|exists:users,id',
            'date' => 'required|date',
            'check_in' => 'required|date',
            // "after:check_in" throws inside the framework when check_in is an array, which the "date" rule already rejects.
            'check_out' => array_merge(['nullable', 'date'], is_array($this->input('check_in')) ? [] : ['after:check_in']),
            'tasks' => 'required|array|min:1|max:20',
            'tasks.*.title' => 'required|string|max:255',
            'reason' => 'required|string|min:10',
        ];
    }

    public function messages(): array
    {
        return [
            'user_id.required' => 'Karyawan wajib dipilih.',
            'user_id.exists' => 'Karyawan tidak ditemukan.',
            'date.required' => 'Tanggal wajib diisi.',
            'date.date' => 'Tanggal tidak valid.',
            'check_in.required' => 'Waktu check-in wajib diisi.',
            'check_in.date' => 'Waktu check-in tidak valid.',
            'check_out.after' => 'Waktu check-out harus setelah check-in.',
            'tasks.required' => 'Minimal satu tugas wajib diisi.',
            'tasks.min' => 'Minimal satu tugas wajib diisi.',
            'tasks.*.title.required' => 'Judul tugas wajib diisi.',
            'tasks.*.title.max' => 'Judul tugas maksimal 255 karakter.',
            'reason.required' => 'Alasan wajib diisi untuk audit.',
            'reason.min' => 'Alasan minimal 10 karakter.',
        ];
    }

    /**
     * Configure the validator instance.
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $userId = $this->input('user_id');
            $manager = auth()->user();

            if (! $userId) {
                return;
            }

            $user = User::find($userId);
            if (! $user) {
                return;
            }

            // Manager can only add attendance for users in their team; super admin can add for any
            if ($manager->isManager() && $user->team_id !== $manager->team_id) {
                $validator->errors()->add('user_id', 'Anda hanya dapat menambah absensi untuk karyawan dalam tim Anda.');
            }

            $date = $this->input('date');
            $checkIn = $this->input('check_in');
            // The after hook also runs when the rules failed; an unparseable value is already reported by them.
            $hasUnparseableDate = $validator->errors()->has('date') || $validator->errors()->has('check_in');
            if ($date && $checkIn && ! $hasUnparseableDate) {
                $dateOnly = \Carbon\Carbon::parse($date)->toDateString();
                $checkInDate = \Carbon\Carbon::parse($checkIn)->toDateString();
                if ($dateOnly !== $checkInDate) {
                    $validator->errors()->add('check_in', 'Tanggal check-in harus sama dengan tanggal absensi.');
                }
            }
        });
    }
}
