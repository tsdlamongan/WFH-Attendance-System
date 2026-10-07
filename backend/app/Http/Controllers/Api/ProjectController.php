<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Project;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

class ProjectController extends Controller
{
    /**
     * Active projects of the user's team, i.e. the choices offered at check-in.
     */
    public function index(): JsonResponse
    {
        try {
            $projects = Project::where('team_id', auth()->user()->team_id)
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name']);

            return response()->json([
                'success' => true,
                'data' => $projects->map(fn (Project $project) => [
                    'id' => $project->id,
                    'name' => $project->name,
                ]),
            ], 200);
        } catch (\Exception $e) {
            Log::error('Get projects failed: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Gagal mengambil daftar project.',
            ], 500);
        }
    }
}
