<?php

namespace App\Console\Commands;

use App\Repositories\AttendanceRepository;
use App\Services\AttendanceService;
use Exception;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class AutoCheckOutCommand extends Command
{
    protected $signature = 'attendance:auto-checkout';

    protected $description = 'Auto checkout employees who have exceeded their team required work hours';

    public function handle(AttendanceService $attendanceService, AttendanceRepository $attendanceRepository): int
    {
        $attendances = $attendanceRepository->findAllActiveExceedingHours();

        $this->info("Found {$attendances->count()} attendance(s) exceeding required work hours.");

        if ($attendances->isEmpty()) {
            return Command::SUCCESS;
        }

        $successCount = 0;
        $failCount = 0;

        foreach ($attendances as $attendance) {
            try {
                $userName = $attendance->user?->name ?? 'Unknown';
                $this->info("Auto checking out: {$userName} (Attendance #{$attendance->id})");

                $attendanceService->autoCheckOut($attendance);
                $successCount++;

                $this->info("  Successfully auto-checked out {$userName}");
            } catch (Exception $e) {
                $failCount++;
                $this->error("  Failed to auto-checkout attendance #{$attendance->id}: {$e->getMessage()}");
                Log::error("Auto checkout command failed for attendance #{$attendance->id}: {$e->getMessage()}");
            }
        }

        $this->info("Auto checkout completed: {$successCount} success, {$failCount} failed.");

        return Command::SUCCESS;
    }
}
