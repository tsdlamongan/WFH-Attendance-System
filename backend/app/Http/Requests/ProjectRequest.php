<?php

namespace App\Http\Requests;

use App\Enums\ProjectStatus;
use App\Models\Project;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProjectRequest extends FormRequest
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
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $teamId = auth()->user()->team_id;

        $uniqueRule = Rule::unique('projects', 'name')
            ->where(fn ($query) => $query->where('team_id', $teamId));

        if ($this->route('id') !== null) {
            $uniqueRule->ignore($this->route('id'));
        }

        return [
            'name' => ['required', 'string', 'max:150', $uniqueRule],
            'pic' => 'nullable|string|max:255',
            'progress' => ['sometimes', 'integer', Rule::in(range(0, 100, Project::PROGRESS_STEP))],
            'status' => ['sometimes', Rule::enum(ProjectStatus::class)],
            'note' => 'nullable|string|max:255',
            'is_active' => 'sometimes|boolean',
        ];
    }

    /**
     * Get the error messages for the defined validation rules.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'Nama project wajib diisi.',
            'name.max' => 'Nama project maksimal 150 karakter.',
            'name.unique' => 'Nama project ini sudah terdaftar untuk tim Anda.',
            'pic.max' => 'PIC maksimal 255 karakter.',
            'progress.integer' => 'Progress harus berupa angka.',
            'progress.in' => 'Progress harus kelipatan '.Project::PROGRESS_STEP.' antara 0 dan 100.',
            'status.enum' => 'Status project tidak valid.',
            'note.max' => 'Catatan maksimal 255 karakter.',
            'is_active.boolean' => 'Status aktif harus bernilai benar atau salah.',
        ];
    }
}
