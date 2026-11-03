<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ProjectRequest;
use App\Http\Resources\ProjectResource;
use App\Models\Project;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

class ManagerProjectController extends Controller
{
    public function index(): JsonResponse
    {
        try {
            $projects = Project::where('team_id', $this->teamId())
                ->withCount('tasks')
                ->orderBy('id')
                ->get();

            return response()->json([
                'success' => true,
                'data' => ProjectResource::collection($projects),
            ], 200);
        } catch (\Throwable $e) {
            return $this->failure($e, 'Gagal mengambil daftar project.');
        }
    }

    public function store(ProjectRequest $request): JsonResponse
    {
        try {
            $project = Project::create($request->validated() + ['team_id' => $this->teamId()]);

            return response()->json([
                'success' => true,
                'data' => new ProjectResource($project),
                'message' => 'Project berhasil ditambahkan.',
            ], 201);
        } catch (\Throwable $e) {
            return $this->failure($e, 'Gagal menambahkan project.');
        }
    }

    public function update(ProjectRequest $request, int $id): JsonResponse
    {
        try {
            $project = $this->findForManager($id);
            $project->update($request->validated());

            return response()->json([
                'success' => true,
                'data' => new ProjectResource($project),
                'message' => 'Project berhasil diperbarui.',
            ], 200);
        } catch (\Throwable $e) {
            return $this->failure($e, 'Gagal memperbarui project.');
        }
    }

    public function destroy(int $id): JsonResponse
    {
        try {
            $project = $this->findForManager($id);

            // Tasks keep the history of who worked on what, so a project in use can only be deactivated.
            if ($project->tasks()->exists()) {
                throw new \DomainException('Project sudah dipakai pada data check-in sehingga tidak dapat dihapus. Nonaktifkan project agar tidak bisa dipilih lagi.');
            }

            $project->delete();

            return response()->json([
                'success' => true,
                'message' => 'Project berhasil dihapus.',
            ], 200);
        } catch (\Throwable $e) {
            return $this->failure($e, 'Gagal menghapus project.');
        }
    }

    /**
     * Team whose projects are managed. A super admin has no team and must impersonate a manager.
     */
    private function teamId(): int
    {
        $teamId = auth()->user()->team_id;

        if (! $teamId) {
            throw new AuthorizationException('Project dikelola per tim. Masuk sebagai manager tim untuk mengelolanya.');
        }

        return $teamId;
    }

    /**
     * Load a project, making sure it belongs to the manager's team.
     */
    private function findForManager(int $id): Project
    {
        $project = Project::findOrFail($id);

        if ($project->team_id !== $this->teamId()) {
            throw new AuthorizationException('Tidak berwenang mengelola project ini.');
        }

        return $project;
    }

    private function failure(\Throwable $e, string $fallbackMessage): JsonResponse
    {
        if ($e instanceof AuthorizationException) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 403);
        }

        if ($e instanceof ModelNotFoundException) {
            return response()->json([
                'success' => false,
                'message' => 'Project tidak ditemukan.',
            ], 404);
        }

        if ($e instanceof \DomainException) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }

        Log::error('Project management failed: '.$e->getMessage());

        return response()->json([
            'success' => false,
            'message' => $fallbackMessage,
        ], 500);
    }
}
