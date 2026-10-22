<?php

namespace Tests\Feature;

use App\Enums\ActivityType;
use App\Models\ActivityLog;
use App\Models\Attendance;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use Tests\Traits\CreatesTeamUsers;

/**
 * An employee hit by the auto checkout could not update their tasks at checkout. They may tick
 * them off afterwards, for the sessions of today and yesterday (Task::AUTO_CHECKOUT_EDIT_DAYS).
 *
 * "Today" in these tests is Wednesday 20 March 2030.
 */
class AutoCheckoutTaskCompletionTest extends TestCase
{
    use CreatesTeamUsers, RefreshDatabase;

    private const URL = '/api/v1/tasks/complete-after-auto-checkout';

    private User $employee;

    private array $headers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2030-03-20 09:00:00'));
        $this->team = $this->createTeam(['required_work_hours' => 7.0]);
        $this->employee = $this->createEmployee();
        $this->headers = $this->headersFor($this->employee);
    }

    private function headersFor(User $user): array
    {
        app('auth')->forgetGuards();

        return ['Authorization' => 'Bearer '.$user->createToken('auth-token')->plainTextToken];
    }

    /**
     * A closed session of $user that started on $checkIn and lasted $hours.
     */
    private function closedSession(User $user, string $checkIn, bool $autoCheckout = true, float $hours = 7.0): Attendance
    {
        $start = Carbon::parse($checkIn);

        return Attendance::create([
            'user_id' => $user->id,
            'check_in' => $start,
            'check_out' => $start->copy()->addMinutes((int) round($hours * 60)),
            'date' => $start->toDateString(),
            'total_hours' => $hours,
            'is_auto_checkout' => $autoCheckout,
        ]);
    }

    private function task(Attendance $attendance, string $title, bool $completed = false, ?string $blocker = Task::AUTO_CHECKOUT_BLOCKER, ?Project $project = null): Task
    {
        return Task::create([
            'attendance_id' => $attendance->id,
            'project_id' => $project?->id,
            'title' => $title,
            'is_completed' => $completed,
            'blocker_reason' => $completed ? null : $blocker,
        ]);
    }

    private function pendingTasks(?array $headers = null): array
    {
        return $this->getJson('/api/v1/attendance/today', $headers ?? $this->headers)
            ->assertOk()
            ->json('data.auto_checkout_pending_tasks');
    }

    public function test_today_status_lists_the_open_tasks_of_recent_auto_checkout_sessions(): void
    {
        $project = Project::factory()->create(['team_id' => $this->team->id, 'name' => 'SIAKAD']);

        // Yesterday evening, crossing midnight: counts for the day it started.
        $yesterday = $this->closedSession($this->employee, '2030-03-19 21:00:00', true, 8.0);
        $this->task($yesterday, 'Integrasi API', false, 'menunggu akses', $project);
        $this->task($yesterday, 'Laporan nilai');
        $this->task($yesterday, 'Sudah beres', true);

        $today = $this->closedSession($this->employee, '2030-03-20 01:00:00');
        $this->task($today, 'Slicing dashboard');

        $pending = $this->pendingTasks();

        $this->assertSame(['Integrasi API', 'Laporan nilai', 'Slicing dashboard'], array_column($pending, 'title'));
        $this->assertSame(['2030-03-19', '2030-03-19', '2030-03-20'], array_column($pending, 'date'));
        $this->assertSame('SIAKAD', $pending[0]['project']);
        $this->assertSame($project->id, $pending[0]['project_id']);
        $this->assertSame('menunggu akses', $pending[0]['blocker_reason']);
        $this->assertNull($pending[1]['project']);
        $this->assertSame(Task::AUTO_CHECKOUT_BLOCKER, $pending[1]['blocker_reason']);
    }

    public function test_today_status_leaves_out_tasks_that_may_not_be_updated(): void
    {
        $stranger = $this->createEmployee();

        $this->task($this->closedSession($this->employee, '2030-03-18 08:00:00'), 'Two days ago');
        $this->task($this->closedSession($this->employee, '2030-03-19 08:00:00', false), 'Checked out by the employee');
        $this->task($this->closedSession($stranger, '2030-03-19 08:00:00'), 'Someone else');
        // A session that is still running is not an auto checkout either.
        $stillWorking = Attendance::create([
            'user_id' => $this->employee->id,
            'check_in' => '2030-03-20 08:00:00',
            'check_out' => null,
            'date' => '2030-03-20',
            'total_hours' => 0,
            'is_auto_checkout' => false,
        ]);
        $this->task($stillWorking, 'Running session');

        $this->assertSame([], $this->pendingTasks());
    }

    public function test_employee_can_complete_the_tasks_an_auto_checkout_left_open(): void
    {
        $yesterday = $this->closedSession($this->employee, '2030-03-19 08:00:00');
        $integration = $this->task($yesterday, 'Integrasi API', false, 'menunggu akses');
        $report = $this->task($yesterday, 'Laporan nilai');
        $untouched = $this->task($yesterday, 'Slicing dashboard');

        $this->postJson(self::URL, ['task_ids' => [$integration->id, $report->id]], $this->headers)
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', '2 tugas ditandai selesai')
            ->assertJsonCount(2, 'data.tasks')
            ->assertJsonPath('data.tasks.0.title', 'Integrasi API')
            ->assertJsonPath('data.tasks.0.is_completed', true);

        // Completed tasks carry no blocker any more.
        $this->assertTrue($integration->fresh()->is_completed);
        $this->assertNull($integration->fresh()->blocker_reason);
        $this->assertTrue($report->fresh()->is_completed);
        $this->assertNull($report->fresh()->blocker_reason);
        $this->assertFalse($untouched->fresh()->is_completed);
        $this->assertSame(Task::AUTO_CHECKOUT_BLOCKER, $untouched->fresh()->blocker_reason);

        // The session itself is left alone.
        $this->assertTrue($yesterday->fresh()->is_auto_checkout);
        $this->assertEquals(7.0, $yesterday->fresh()->total_hours);

        $this->assertSame(['Slicing dashboard'], array_column($this->pendingTasks(), 'title'));
    }

    public function test_completed_tasks_are_no_longer_offered_as_carry_over(): void
    {
        $task = $this->task($this->closedSession($this->employee, '2030-03-19 08:00:00'), 'Integrasi API');

        $this->getJson('/api/v1/tasks/incomplete-last-session', $this->headers)
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->postJson(self::URL, ['task_ids' => [$task->id]], $this->headers)->assertOk();

        $this->getJson('/api/v1/tasks/incomplete-last-session', $this->headers)
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_completing_tasks_is_written_to_the_activity_log(): void
    {
        $session = $this->closedSession($this->employee, '2030-03-19 08:00:00');
        $first = $this->task($session, 'Integrasi API');
        $second = $this->task($session, 'Laporan nilai');

        $this->postJson(self::URL, ['task_ids' => [$first->id, $second->id]], $this->headers)->assertOk();

        $log = ActivityLog::where('user_id', $this->employee->id)
            ->where('action', ActivityType::TASK_UPDATED->value)
            ->latest('id')
            ->first();

        $this->assertNotNull($log);
        $this->assertSame('User completed 2 task(s) left open by an auto checkout', $log->description);
    }

    public function test_the_same_task_twice_in_one_save_is_refused(): void
    {
        $task = $this->task($this->closedSession($this->employee, '2030-03-19 08:00:00'), 'Integrasi API');

        $this->postJson(self::URL, ['task_ids' => [$task->id, $task->id]], $this->headers)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['task_ids.0' => 'Tugas yang dipilih tidak boleh dobel.']);

        $this->assertFalse($task->fresh()->is_completed);
    }

    public function test_tasks_that_may_not_be_updated_are_refused_and_nothing_changes(): void
    {
        $eligible = $this->task($this->closedSession($this->employee, '2030-03-19 08:00:00'), 'Eligible');

        $stranger = $this->createEmployee();
        $others = [
            'another employee' => $this->task($this->closedSession($stranger, '2030-03-19 08:00:00'), 'Someone else'),
            'a regular checkout' => $this->task($this->closedSession($this->employee, '2030-03-19 14:00:00', false), 'Checked out by the employee'),
            'before yesterday' => $this->task($this->closedSession($this->employee, '2030-03-18 08:00:00'), 'Two days ago'),
            'already completed' => $this->task($this->closedSession($this->employee, '2030-03-19 16:00:00'), 'Done already', true),
        ];

        foreach ($others as $reason => $task) {
            $this->postJson(self::URL, ['task_ids' => [$eligible->id, $task->id]], $this->headers)
                ->assertUnprocessable()
                ->assertJsonPath('success', false)
                ->assertJsonPath('message', 'Hanya tugas yang belum selesai dari sesi yang terkena auto checkout (hari ini atau kemarin) yang dapat diselesaikan.');

            // All or nothing: the task that would have been fine stays open, too.
            $this->assertFalse($eligible->fresh()->is_completed, "{$reason} must not complete the eligible task");
        }

        $this->assertFalse($others['another employee']->fresh()->is_completed);
        $this->assertFalse($others['before yesterday']->fresh()->is_completed);
    }

    public function test_an_unknown_task_is_refused(): void
    {
        $this->postJson(self::URL, ['task_ids' => [999999]], $this->headers)
            ->assertUnprocessable()
            ->assertJsonPath('success', false);
    }

    public function test_tasks_stay_editable_through_the_next_day(): void
    {
        $task = $this->task($this->closedSession($this->employee, '2030-03-18 08:00:00'), 'Auto-checked out on Monday');

        // Wednesday: Monday is two days back, out of reach.
        $this->assertSame([], $this->pendingTasks());

        // Tuesday: Monday is yesterday.
        $this->travelTo(Carbon::parse('2030-03-19 09:00:00'));
        $headers = $this->headersFor($this->employee);

        $this->assertSame(['Auto-checked out on Monday'], array_column($this->pendingTasks($headers), 'title'));
        $this->postJson(self::URL, ['task_ids' => [$task->id]], $headers)->assertOk();
    }

    #[DataProvider('invalidPayloads')]
    public function test_the_request_is_validated(array $payload, string $field, string $message): void
    {
        $this->postJson(self::URL, $payload, $this->headers)
            ->assertUnprocessable()
            ->assertJsonValidationErrors([$field => $message]);
    }

    public static function invalidPayloads(): array
    {
        return [
            'no tasks' => [[], 'task_ids', 'Pilih minimal satu tugas.'],
            'empty list' => [['task_ids' => []], 'task_ids', 'Pilih minimal satu tugas.'],
            'not a list' => [['task_ids' => 5], 'task_ids', 'Daftar tugas tidak valid.'],
            'not a number' => [['task_ids' => ['abc']], 'task_ids.0', 'ID tugas tidak valid.'],
            'too many' => [['task_ids' => range(1, 101)], 'task_ids', 'Maksimal 100 tugas sekali simpan.'],
        ];
    }

    public function test_only_employees_can_complete_tasks(): void
    {
        $task = $this->task($this->closedSession($this->employee, '2030-03-19 08:00:00'), 'Integrasi API');

        $this->postJson(self::URL, ['task_ids' => [$task->id]])->assertUnauthorized();
        $this->postJson(self::URL, ['task_ids' => [$task->id]], $this->headersFor($this->createManager()))->assertForbidden();

        $this->assertFalse($task->fresh()->is_completed);
    }

    public function test_a_disabled_employee_is_cut_off(): void
    {
        $task = $this->task($this->closedSession($this->employee, '2030-03-19 08:00:00'), 'Integrasi API');
        $this->employee->update(['is_disabled' => true]);

        $this->postJson(self::URL, ['task_ids' => [$task->id]], $this->headers)->assertForbidden();

        $this->assertFalse($task->fresh()->is_completed);
    }
}
