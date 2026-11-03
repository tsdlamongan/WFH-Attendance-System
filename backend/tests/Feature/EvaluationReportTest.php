<?php

use App\Enums\LeaveStatus;
use App\Enums\UserRole;
use App\Http\Controllers\Api\EvaluationReportController;
use App\Models\Attendance;
use App\Models\Leave;
use App\Models\Project;
use App\Models\Task;
use App\Models\Team;
use App\Models\User;
use App\Services\EvaluationReportPdf;
use App\Services\EvaluationReportService;
use Carbon\Carbon;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Tests\Traits\CreatesTeamUsers;

uses(CreatesTeamUsers::class);

/*
 * Evaluation report ("Laporan Evaluasi") of one or more teams, merged into one report: staff hours
 * from the check-in data plus the work employees logged per project, as JSON preview and as PDF.
 * Super admin only.
 *
 * Period under test: Monday 18 - Saturday 23 March 2030 with a holiday on Thursday 21,
 * so 5 working days and 40 expected hours.
 */

/**
 * Bearer header for a user. Guards are reset so several actors can be used in one test.
 */
function erAuth(User $user): array
{
    app('auth')->forgetGuards();

    return ['Authorization' => 'Bearer '.$user->createToken('auth-token')->plainTextToken];
}

/**
 * A session of the given length on a day of March 2030 (open when $hours is null).
 */
function erSession(User $user, int $day, ?float $hours = 8.0): Attendance
{
    $checkIn = Carbon::parse(sprintf('2030-03-%02d 08:00:00', $day));

    return Attendance::create([
        'user_id' => $user->id,
        'check_in' => $checkIn,
        'check_out' => $hours === null ? null : $checkIn->copy()->addMinutes((int) round($hours * 60)),
        'date' => $checkIn->toDateString(),
        'total_hours' => $hours ?? 0,
    ]);
}

function erTask(Attendance $attendance, ?Project $project, string $title, bool $isCompleted = true, ?string $blocker = null): Task
{
    return Task::create([
        'attendance_id' => $attendance->id,
        'project_id' => $project?->id,
        'title' => $title,
        'is_completed' => $isCompleted,
        'blocker_reason' => $blocker,
    ]);
}

/**
 * @param  Team|array<int, Team>  $teams
 */
function erBuild(Team|array $teams, string $start = '2030-03-18', string $end = '2030-03-23', ?string $title = null): array
{
    return app(EvaluationReportService::class)->build(
        collect(Arr::wrap($teams)),
        Carbon::parse($start)->startOfDay(),
        Carbon::parse($end)->endOfDay(),
        $title
    );
}

/**
 * Number of pages of a PDF document.
 */
function erPageCount(string $pdf): int
{
    return preg_match_all('/\/Type\s*\/Page\b(?!s)/', $pdf);
}

/**
 * Minimal report array for the renderer.
 */
function erReport(int $staffCount = 0, array $projectRows = [], string $title = 'DIVISI IT DEVELOPMENT'): array
{
    $staffRows = [];
    for ($i = 1; $i <= $staffCount; $i++) {
        $staffRows[] = [
            'no' => $i,
            'name' => "Karyawan Nomor {$i}",
            'job_title' => 'Fullstack Developer',
            'employment_type' => 'permanent',
            'total_hours' => 88.08,
            'hours_label' => '88 Jam 5 Menit',
            'rating' => 'Sangat Baik',
            'rating_color' => '#09963b',
        ];
    }

    return [
        'teams' => [['id' => 1, 'name' => 'IT Development']],
        'title' => $title,
        'period' => ['start_date' => '2030-03-18', 'end_date' => '2030-03-23', 'label' => '18 – 23 Maret 2030'],
        'human_resources' => [
            'summary' => ['total' => $staffCount, 'permanent' => $staffCount, 'intern' => 0, 'attendance_percentage' => $staffCount > 0 ? 97.5 : null],
            'teams' => [['id' => 1, 'name' => 'IT Development', 'working_days' => 5, 'expected_hours' => 40.0]],
            'rows' => $staffRows,
        ],
        'projects' => [
            'summary' => ['total' => count($projectRows), 'completed' => 0, 'on_progress' => count($projectRows), 'hold' => 0],
            'rows' => $projectRows,
        ],
    ];
}

beforeEach(function () {
    $this->travelTo(Carbon::parse('2030-03-25 10:00:00'));
    $this->team = $this->createTeam(['name' => 'IT Development', 'required_work_hours' => 7.0]);
    $this->manager = $this->createManager(['name' => 'Zainal Manager', 'job_title' => 'Manager IT']);
    $this->superAdmin = User::factory()->create(['role' => UserRole::SUPER_ADMIN, 'team_id' => null, 'name' => 'Super Admin']);
});

describe('staff section', function () {
    beforeEach(function () {
        $this->createHoliday(['date' => '2030-03-21', 'name' => 'Libur Nasional']);
        $this->createManager(['name' => 'Abdul Leader']); // no job title

        $this->alice = $this->createEmployee(['name' => 'Alice', 'job_title' => 'Fullstack Developer']);
        $this->bob = $this->createEmployee(['name' => 'Bob']); // no job title
        $this->eve = $this->createEmployee(['name' => 'eve', 'job_title' => 'Quality Assurance']);
        $this->carol = User::factory()->intern()->create(['team_id' => $this->team->id, 'name' => 'Carol', 'job_title' => 'UI/UX Designer']);
        $this->dave = User::factory()->intern()->create(['team_id' => $this->team->id, 'name' => 'Dave', 'job_title' => 'Fullstack Developer']);

        // Never listed: disabled, another team, super admin.
        $this->createDisabledEmployee(['name' => 'Disabled Dan']);
        User::factory()->create(['team_id' => $this->createTeam()->id, 'name' => 'Orang Lain']);
        User::factory()->create(['team_id' => null, 'role' => UserRole::SUPER_ADMIN, 'name' => 'Super Admin']);

        // Alice: all 5 working days, 40h. Bob: 4 days + 1 approved leave day (credited 8h).
        foreach ([18, 19, 20, 22, 23] as $day) {
            erSession($this->alice, $day);
        }
        foreach ([18, 19, 20, 22] as $day) {
            erSession($this->bob, $day);
        }
        Leave::factory()->create([
            'user_id' => $this->bob->id,
            'start_date' => '2030-03-23',
            'end_date' => '2030-03-23',
            'status' => LeaveStatus::APPROVED,
        ]);
        // Carol: 37.5h (93.75%). Dave: 20.08h on 3 days (50.2%). eve: nothing.
        foreach ([18, 19, 20, 22] as $day) {
            erSession($this->carol, $day);
        }
        erSession($this->carol, 23, 5.5);
        erSession($this->dave, 18);
        erSession($this->dave, 19);
        erSession($this->dave, 20, 4.08);
    });

    it('lists managers, permanent employees and interns with hours and rating', function () {
        $staff = erBuild($this->team)['human_resources'];

        expect(array_column($staff['rows'], 'name'))->toBe(['Abdul Leader', 'Zainal Manager', 'Alice', 'Bob', 'eve', 'Carol', 'Dave'])
            ->and(array_column($staff['rows'], 'no'))->toBe([1, 2, 3, 4, 5, 6, 7])
            ->and(array_column($staff['rows'], 'job_title'))->toBe(['Manager', 'Manager IT', 'Fullstack Developer', '-', 'Quality Assurance', 'UI/UX Designer', 'Fullstack Developer'])
            ->and(array_column($staff['rows'], 'employment_type'))->toBe(['permanent', 'permanent', 'permanent', 'permanent', 'permanent', 'intern', 'intern'])
            ->and(array_column($staff['rows'], 'hours_label'))->toBe(['', '', '40 Jam', '40 Jam', '0 Jam', '37 Jam 30 Menit', '20 Jam 5 Menit'])
            ->and(array_column($staff['rows'], 'rating'))->toBe([null, null, 'Sangat Baik', 'Sangat Baik', 'Kurang Baik', 'Baik', 'Kurang Baik'])
            ->and(array_column($staff['rows'], 'rating_color'))->toBe([null, null, '#09963b', '#09963b', '#f0b400', '#00a5ab', '#f0b400'])
            ->and($staff['rows'][0]['total_hours'])->toBeNull()
            ->and($staff['rows'][5]['total_hours'])->toBe(37.5);
    });

    it('summarises head count, employment types and attendance', function () {
        $summary = erBuild($this->team)['human_resources']['summary'];

        // Attended working days: Alice 5, Bob 4 + 1 leave, Carol 5, Dave 3, eve 0 = 18 of 25.
        expect($summary)->toBe([
            'total' => 7,
            'permanent' => 5,
            'intern' => 2,
            'attendance_percentage' => 72.0,
        ])
            ->and(erBuild($this->team)['human_resources']['teams'])->toBe([
                ['id' => $this->team->id, 'name' => 'IT Development', 'working_days' => 5, 'expected_hours' => 40.0],
            ]);
    });

    it('does not count more attended days than there are working days', function () {
        // Alice also worked on the holiday: still 5 of 5 working days for the attendance rate.
        erSession($this->alice, 21);

        $staff = erBuild($this->team)['human_resources'];

        expect($staff['summary']['attendance_percentage'])->toBe(72.0)
            ->and($staff['rows'][2]['hours_label'])->toBe('48 Jam');
    });

    it('follows the rating thresholds of the config', function () {
        config(['evaluation_report.hour_ratings' => [
            ['min' => 0, 'label' => 'Perlu Evaluasi', 'color' => '#ff0000'],
            ['min' => 50, 'label' => 'Cukup', 'color' => '#00ff00'],
        ]]);

        $rows = erBuild($this->team)['human_resources']['rows'];

        expect(array_column($rows, 'rating'))->toBe([null, null, 'Cukup', 'Cukup', 'Perlu Evaluasi', 'Cukup', 'Cukup'])
            ->and($rows[4]['rating_color'])->toBe('#ff0000');
    });

    it('leaves rating and attendance empty for a period without working days', function () {
        // Sunday 24 March 2030: nothing is expected, so there is nothing to rate.
        erSession($this->alice, 24, 0.75);

        $staff = erBuild($this->team, '2030-03-24', '2030-03-24')['human_resources'];

        expect($staff['summary']['attendance_percentage'])->toBeNull()
            ->and($staff['teams'][0]['working_days'])->toBe(0)
            ->and($staff['rows'][2]['hours_label'])->toBe('45 Menit')
            ->and($staff['rows'][2]['rating'])->toBeNull()
            ->and($staff['rows'][2]['rating_color'])->toBeNull();
    });
});

describe('work section', function () {
    beforeEach(function () {
        $this->alice = $this->createEmployee(['name' => 'Alice']);
        $this->bob = $this->createEmployee(['name' => 'Bob']);

        $this->santri = Project::factory()->completed()->create(['team_id' => $this->team->id, 'name' => 'Santri Link', 'pic' => 'Nico', 'note' => 'Development Selesai']);
        $this->siakad = Project::factory()->create(['team_id' => $this->team->id, 'name' => 'SIAKAD', 'progress' => 60]);
        $this->sukasir = Project::factory()->hold()->create(['team_id' => $this->team->id, 'name' => 'Sukasir', 'progress' => 40]);
        $this->archived = Project::factory()->inactive()->create(['team_id' => $this->team->id, 'name' => 'Arsip Tanpa Aktivitas']);
        $this->legacy = Project::factory()->inactive()->create(['team_id' => $this->team->id, 'name' => 'Legacy App', 'progress' => 90]);
        Project::factory()->create(['name' => 'Project Tim Lain']);

        $aliceMonday = erSession($this->alice, 18);
        $aliceTuesday = erSession($this->alice, 19);
        $bobTuesday = erSession($this->bob, 19);
        $bobSaturday = erSession($this->bob, 23, null); // still running

        erTask($aliceMonday, $this->santri, 'Fixing WA Notif');
        erTask($aliceMonday, $this->siakad, 'Integrasi   API', false, 'menunggu akses sandbox');
        erTask($aliceTuesday, $this->siakad, 'integrasi api'); // carried over and finished
        erTask($aliceTuesday, $this->siakad, 'Laporan nilai', false, 'menunggu data dosen');
        erTask($bobTuesday, $this->siakad, 'Slicing dashboard', false, Task::AUTO_CHECKOUT_BLOCKER);
        erTask($bobTuesday, $this->siakad, 'Perbaikan login', false); // closed without a blocker
        erTask($bobSaturday, $this->siakad, 'Regression test', false); // in progress, not a problem yet
        erTask($bobTuesday, $this->legacy, 'Migrasi data');
        erTask($bobTuesday, null, 'Rapat mingguan');

        // Outside the period: ignored.
        erTask(erSession($this->alice, 11), $this->sukasir, 'Pekerjaan minggu lalu');
        erTask(erSession($this->alice, 25), $this->sukasir, 'Pekerjaan minggu depan');
    });

    it('lists active projects and inactive ones with work in the period', function () {
        $work = erBuild($this->team)['projects'];

        expect(array_column($work['rows'], 'name'))->toBe(['Santri Link', 'SIAKAD', 'Sukasir', 'Legacy App'])
            ->and(array_column($work['rows'], 'no'))->toBe([1, 2, 3, 4])
            ->and(array_column($work['rows'], 'id'))->toBe([$this->santri->id, $this->siakad->id, $this->sukasir->id, $this->legacy->id])
            ->and($work['summary'])->toBe(['total' => 4, 'completed' => 1, 'on_progress' => 2, 'hold' => 1]);
    });

    it('takes PIC, progress and note from the project and falls back to the logged work', function () {
        $rows = erBuild($this->team)['projects']['rows'];

        expect($rows[0])->toMatchArray(['pic' => 'Nico', 'progress' => 100, 'note' => 'Development Selesai', 'status' => 'completed', 'tasks_count' => 1])
            ->and($rows[1])->toMatchArray(['pic' => 'Alice, Bob', 'progress' => 60, 'note' => 'On-Progress', 'status' => 'on_progress', 'tasks_count' => 6])
            ->and($rows[2])->toMatchArray(['pic' => '-', 'progress' => 40, 'note' => 'Hold', 'status' => 'hold', 'tasks_count' => 0])
            ->and($rows[3])->toMatchArray(['pic' => 'Bob', 'progress' => 90, 'note' => 'On-Progress', 'tasks_count' => 1]);
    });

    it('summarises the logged work per project with the reasons of open tasks, but not the auto checkout status', function () {
        $rows = erBuild($this->team)['projects']['rows'];

        expect($rows[0]['problem'])->toBe('Fixing WA Notif')
            ->and($rows[1]['problem'])->toBe('integrasi api; Laporan nilai (menunggu data dosen); Slicing dashboard; Perbaikan login; Regression test')
            ->and($rows[2]['problem'])->toBe('Tidak ada')
            ->and($rows[3]['problem'])->toBe('Migrasi data');
    });

    it('treats a dash as no reason, so it is not printed either', function () {
        $project = Project::factory()->create(['team_id' => $this->team->id, 'name' => 'Cartrack']);
        $session = erSession($this->bob, 20);

        erTask($session, $project, 'Fixing ekspor', false, ' - ');
        erTask($session, $project, 'Fixing filter', false, 'menunggu data');

        $row = collect(erBuild($this->team)['projects']['rows'])->firstWhere('name', 'Cartrack');

        expect($row['problem'])->toBe('Fixing ekspor; Fixing filter (menunggu data)');
    });

    it('leaves links out of the logged work and drops titles that were only a link', function () {
        $project = Project::factory()->create(['team_id' => $this->team->id, 'name' => 'Cartrack']);
        $session = erSession($this->bob, 20);

        erTask($session, $project, 'https://app.clickup.com/t/9018127018/z94x8e7qbc', false, Task::AUTO_CHECKOUT_BLOCKER);
        erTask($session, $project, 'Keyword putar rekaman tertimpa https://imgur.com/YwYRXmU');
        erTask($session, $project, 'Perbaikan ekspor: https://app.clickup.com/t/9018127018/z94x8e8cbe', false, 'menunggu data');

        $row = collect(erBuild($this->team)['projects']['rows'])->firstWhere('name', 'Cartrack');

        expect($row['problem'])->toBe('Keyword putar rekaman tertimpa; Perbaikan ekspor (menunggu data)')
            ->and($row['tasks_count'])->toBe(3);
    });

    it('reports no projects for a team that has none', function () {
        $work = erBuild($this->createTeam())['projects'];

        expect($work['rows'])->toBe([])
            ->and($work['summary'])->toBe(['total' => 0, 'completed' => 0, 'on_progress' => 0, 'hold' => 0]);
    });
});

describe('title and period', function () {
    it('prefixes the team name with DIVISI unless it is already there', function (string $teamName, string $title) {
        $team = $this->createTeam(['name' => $teamName]);

        expect(erBuild($team)['title'])->toBe($title)
            ->and(erBuild($team)['teams'])->toBe([['id' => $team->id, 'name' => $teamName]]);
    })->with([
        ['IT Development', 'DIVISI IT DEVELOPMENT'],
        ['Divisi  Keuangan', 'DIVISI KEUANGAN'],
        ['divisi hrd', 'DIVISI HRD'],
        ['Divisi', 'DIVISI'],
    ]);

    it('joins the names of several teams into one title, in the order given', function () {
        $keuangan = $this->createTeam(['name' => 'Divisi Keuangan']);
        $hrd = $this->createTeam(['name' => 'HRD']);
        $desain = $this->createTeam(['name' => 'Desain']);

        expect(erBuild([$this->team, $keuangan])['title'])->toBe('DIVISI IT DEVELOPMENT & KEUANGAN')
            ->and(erBuild([$this->team, $keuangan, $hrd, $desain])['title'])->toBe('DIVISI IT DEVELOPMENT, KEUANGAN, HRD & DESAIN')
            ->and(erBuild([$this->team, $keuangan])['teams'])->toHaveCount(2);
    });

    it('uses a custom title instead of the one made from the team names', function () {
        $keuangan = $this->createTeam(['name' => 'Divisi Keuangan']);

        $report = erBuild([$this->team, $keuangan], title: 'Evaluasi  Kinerja   Triwulan I');

        // Typed text is kept as it is (no DIVISI prefix, no upper-casing), only stray whitespace goes.
        expect($report['title'])->toBe('Evaluasi Kinerja Triwulan I')
            // The teams still decide whose staff and projects are in the report.
            ->and($report['teams'])->toHaveCount(2)
            ->and($report['human_resources']['summary']['total'])->toBe(1);
    });

    it('falls back to the title of the teams when the custom title is blank', function (?string $title) {
        expect(erBuild($this->team, title: $title)['title'])->toBe('DIVISI IT DEVELOPMENT');
    })->with([
        'null' => [null],
        'empty' => [''],
        'only spaces' => ["  \t "],
    ]);

    it('writes the period in Indonesian', function (string $start, string $end, string $label) {
        $period = erBuild($this->team, $start, $end)['period'];

        expect($period)->toBe(['start_date' => $start, 'end_date' => $end, 'label' => $label]);
    })->with([
        'single day' => ['2030-03-18', '2030-03-18', '18 Maret 2030'],
        'same month' => ['2030-03-18', '2030-03-23', '18 – 23 Maret 2030'],
        'across months' => ['2026-08-19', '2026-09-01', '19 Agustus – 1 September 2026'],
        'across years' => ['2029-12-25', '2030-01-05', '25 Desember 2029 – 5 Januari 2030'],
    ]);
});

describe('several teams in one report', function () {
    beforeEach(function () {
        // Only IT Development has a holiday: 5 working days (40h) against 6 (48h) for Desain.
        $this->createHoliday(['date' => '2030-03-21', 'name' => 'Libur Nasional']);
        $this->desain = $this->createTeam(['name' => 'Desain', 'required_work_hours' => 7.0]);

        $this->alice = $this->createEmployee(['name' => 'Alice', 'job_title' => 'Fullstack Developer']);
        $this->zed = User::factory()->create(['team_id' => $this->desain->id, 'role' => UserRole::EMPLOYEE, 'name' => 'Zed', 'job_title' => 'Desainer']);
        $this->mia = User::factory()->intern()->create(['team_id' => $this->desain->id, 'name' => 'Mia', 'job_title' => 'UI/UX Designer']);
        User::factory()->create(['team_id' => $this->desain->id, 'role' => UserRole::MANAGER, 'name' => 'Nina Lead']);

        // Never listed: a team that is not part of the report.
        User::factory()->create(['team_id' => $this->createTeam()->id, 'name' => 'Orang Lain']);

        foreach ([18, 19, 20, 21, 22, 23] as $day) {
            erSession($this->mia, $day);
        }
        foreach ([18, 19, 20, 22, 23] as $day) {
            erSession($this->alice, $day);
            erSession($this->zed, $day);
        }
    });

    it('merges the staff of every team into one list', function () {
        $staff = erBuild([$this->team, $this->desain])['human_resources'];

        expect(array_column($staff['rows'], 'name'))->toBe(['Nina Lead', 'Zainal Manager', 'Alice', 'Zed', 'Mia'])
            ->and(array_column($staff['rows'], 'no'))->toBe([1, 2, 3, 4, 5])
            ->and(array_column($staff['rows'], 'job_title'))->toBe(['Manager', 'Manager IT', 'Fullstack Developer', 'Desainer', 'UI/UX Designer'])
            ->and($staff['summary'])->toMatchArray(['total' => 5, 'permanent' => 4, 'intern' => 1]);
    });

    it('rates everyone against the expected hours of their own team', function () {
        $staff = erBuild([$this->team, $this->desain])['human_resources'];

        // Alice and Zed both worked 40h: all of the 40h IT Development expects, but 83% of the 48h Desain expects.
        expect(array_column($staff['rows'], 'hours_label'))->toBe(['', '', '40 Jam', '40 Jam', '48 Jam'])
            ->and(array_column($staff['rows'], 'rating'))->toBe([null, null, 'Sangat Baik', 'Kurang Baik', 'Sangat Baik'])
            ->and($staff['teams'])->toBe([
                ['id' => $this->team->id, 'name' => 'IT Development', 'working_days' => 5, 'expected_hours' => 40.0],
                ['id' => $this->desain->id, 'name' => 'Desain', 'working_days' => 6, 'expected_hours' => 48.0],
            ]);
    });

    it('measures attendance over all working days of all teams', function () {
        // Attended working days: Alice 5 of 5, Zed 5 of 6, Mia 6 of 6 = 16 of 17.
        expect(erBuild([$this->team, $this->desain])['human_resources']['summary']['attendance_percentage'])->toBe(94.1);
    });

    it('merges the projects of every team into one list', function () {
        $santri = Project::factory()->create(['team_id' => $this->team->id, 'name' => 'Santri Link']);
        $branding = Project::factory()->hold()->create(['team_id' => $this->desain->id, 'name' => 'Branding']);
        Project::factory()->create(['name' => 'Project Tim Lain']);
        erTask(erSession($this->alice, 18), $santri, 'Fixing WA Notif');
        erTask(erSession($this->zed, 19), $branding, 'Logo baru');

        $work = erBuild([$this->team, $this->desain])['projects'];

        expect(array_column($work['rows'], 'name'))->toBe(['Santri Link', 'Branding'])
            ->and(array_column($work['rows'], 'no'))->toBe([1, 2])
            ->and(array_column($work['rows'], 'pic'))->toBe(['Alice', 'Zed'])
            ->and(array_column($work['rows'], 'problem'))->toBe(['Fixing WA Notif', 'Logo baru'])
            ->and($work['summary'])->toBe(['total' => 2, 'completed' => 0, 'on_progress' => 1, 'hold' => 1]);
    });

    it('leaves out the teams that were not selected', function () {
        $staff = erBuild($this->desain)['human_resources'];

        expect(array_column($staff['rows'], 'name'))->toBe(['Nina Lead', 'Zed', 'Mia'])
            ->and(array_column($staff['teams'], 'name'))->toBe(['Desain']);
    });
});

/**
 * URL of the report with the teams and period in the query string.
 *
 * @param  array<int, int>  $teamIds
 */
function erUrl(array $teamIds, string $format = '', string $start = '2030-03-18', string $end = '2030-03-23', ?string $title = null): string
{
    return '/api/v1/super-admin/reports/evaluation'.$format.'?'.http_build_query([
        'team_ids' => $teamIds,
        'start_date' => $start,
        'end_date' => $end,
        'title' => $title,
    ]);
}

/**
 * Title of a PDF document (taken from the HTML <title>; dompdf stores it as UTF-16BE).
 */
function erPdfTitle(string $pdf): string
{
    preg_match('/\/Title\s*\((.*?)\)\s*\n/s', $pdf, $matches);

    return mb_convert_encoding(substr($matches[1] ?? '', 2), 'UTF-8', 'UTF-16BE');
}

describe('GET /super-admin/reports/evaluation', function () {
    it('requires authentication and the super admin role', function (string $format) {
        $url = erUrl([$this->team->id], $format);

        $this->getJson($url)->assertUnauthorized();
        $this->getJson($url, erAuth($this->createEmployee()))->assertForbidden();
        $this->getJson($url, erAuth($this->manager))->assertForbidden();
    })->with([
        'preview' => [''],
        'pdf' => ['/pdf'],
    ]);

    it('is no longer a manager report', function () {
        $this->getJson('/api/v1/manager/reports/evaluation?start_date=2030-03-18&end_date=2030-03-23', erAuth($this->manager))
            ->assertNotFound();
        $this->getJson('/api/v1/manager/reports/evaluation/pdf?start_date=2030-03-18&end_date=2030-03-23', erAuth($this->manager))
            ->assertNotFound();
    });

    it('returns the merged report of the selected teams', function () {
        $desain = $this->createTeam(['name' => 'Desain']);
        $alice = $this->createEmployee(['name' => 'Alice']);
        $zed = User::factory()->create(['team_id' => $desain->id, 'role' => UserRole::EMPLOYEE, 'name' => 'Zed']);
        $santri = Project::factory()->create(['team_id' => $this->team->id, 'name' => 'Santri Link']);
        $branding = Project::factory()->create(['team_id' => $desain->id, 'name' => 'Branding']);
        erTask(erSession($alice, 18), $santri, 'Fixing WA Notif');
        erTask(erSession($zed, 18), $branding, 'Logo baru');
        Project::factory()->create(['name' => 'Project Tim Lain']);

        // Selected in the opposite order of the title: the report orders the teams by name.
        $this->getJson(erUrl([$this->team->id, $desain->id]), erAuth($this->superAdmin))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.title', 'DIVISI DESAIN & IT DEVELOPMENT')
            ->assertJsonPath('data.period.label', '18 – 23 Maret 2030')
            ->assertJsonPath('data.teams.0.name', 'Desain')
            ->assertJsonPath('data.teams.1.name', 'IT Development')
            ->assertJsonPath('data.human_resources.summary.total', 3)
            ->assertJsonPath('data.human_resources.rows.1.name', 'Alice')
            ->assertJsonPath('data.human_resources.rows.2.name', 'Zed')
            ->assertJsonCount(2, 'data.projects.rows')
            ->assertJsonPath('data.projects.rows.0.name', 'Branding')
            ->assertJsonPath('data.projects.rows.1.name', 'Santri Link');
    });

    it('returns the report of a single team', function () {
        $alice = $this->createEmployee(['name' => 'Alice']);
        $project = Project::factory()->create(['team_id' => $this->team->id, 'name' => 'Santri Link']);
        erTask(erSession($alice, 18), $project, 'Fixing WA Notif');

        $this->getJson(erUrl([$this->team->id]), erAuth($this->superAdmin))
            ->assertOk()
            ->assertJsonPath('data.title', 'DIVISI IT DEVELOPMENT')
            ->assertJsonCount(1, 'data.teams')
            ->assertJsonPath('data.human_resources.summary.total', 2)
            ->assertJsonPath('data.projects.rows.0.problem', 'Fixing WA Notif');
    });

    it('returns the report under a custom title', function () {
        $desain = $this->createTeam(['name' => 'Desain']);

        $this->getJson(erUrl([$this->team->id, $desain->id], title: 'Evaluasi Kinerja Triwulan I'), erAuth($this->superAdmin))
            ->assertOk()
            ->assertJsonPath('data.title', 'Evaluasi Kinerja Triwulan I')
            // The teams still decide whose staff and projects are in the report.
            ->assertJsonCount(2, 'data.teams');
    });

    it('keeps the title of the teams when no custom title is sent', function (?string $title) {
        $this->getJson(erUrl([$this->team->id], title: $title), erAuth($this->superAdmin))
            ->assertOk()
            ->assertJsonPath('data.title', 'DIVISI IT DEVELOPMENT');
    })->with([
        'no title' => [null],
        'empty title' => [''],
        'blank title' => ['   '],
    ]);

    it('validates the title', function (string $query, string $message) {
        foreach (['/api/v1/super-admin/reports/evaluation', '/api/v1/super-admin/reports/evaluation/pdf'] as $url) {
            $this->getJson($url.'?start_date=2030-03-18&end_date=2030-03-23&team_ids[]='.$this->team->id.$query, erAuth($this->superAdmin))
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['title' => $message]);
        }
    })->with([
        'too long' => ['&title='.str_repeat('a', 71), 'Judul laporan maksimal 70 karakter.'],
        'not text' => ['&title[]=a', 'Judul laporan tidak valid.'],
    ]);

    it('accepts a title of exactly the maximum length', function () {
        $title = str_repeat('a', EvaluationReportController::MAX_TITLE_LENGTH);

        $this->getJson(erUrl([$this->team->id], title: $title), erAuth($this->superAdmin))
            ->assertOk()
            ->assertJsonPath('data.title', $title);
    });

    it('validates the period', function (string $query, string $field, string $message) {
        foreach (['/api/v1/super-admin/reports/evaluation', '/api/v1/super-admin/reports/evaluation/pdf'] as $url) {
            $this->getJson($url.$query.'&team_ids[]='.$this->team->id, erAuth($this->superAdmin))
                ->assertUnprocessable()
                ->assertJsonPath("errors.{$field}.0", $message);
        }
    })->with([
        'no start' => ['?end_date=2030-03-23', 'start_date', 'Tanggal mulai wajib diisi.'],
        'no end' => ['?start_date=2030-03-18', 'end_date', 'Tanggal selesai wajib diisi.'],
        'malformed start' => ['?start_date=18-03-2030&end_date=2030-03-23', 'start_date', 'Tanggal mulai tidak valid.'],
        'malformed end' => ['?start_date=2030-03-18&end_date=besok', 'end_date', 'Tanggal selesai tidak valid.'],
        'end before start' => ['?start_date=2030-03-18&end_date=2030-03-17', 'end_date', 'Tanggal selesai tidak boleh sebelum tanggal mulai.'],
        'longer than a year' => ['?start_date=2029-01-01&end_date=2030-03-23', 'end_date', 'Periode laporan maksimal 366 hari.'],
    ]);

    it('validates the teams', function (string $query, string $field, string $message) {
        foreach (['/api/v1/super-admin/reports/evaluation', '/api/v1/super-admin/reports/evaluation/pdf'] as $url) {
            $this->getJson($url.'?start_date=2030-03-18&end_date=2030-03-23'.str_replace('{team}', (string) $this->team->id, $query), erAuth($this->superAdmin))
                ->assertUnprocessable()
                ->assertJsonValidationErrors([$field => $message]);
        }
    })->with([
        'no team' => ['', 'team_ids', 'Pilih minimal satu tim.'],
        'not a list' => ['&team_ids=1', 'team_ids', 'Daftar tim tidak valid.'],
        'not a number' => ['&team_ids[]=alpha', 'team_ids.0', 'Tim yang dipilih tidak valid.'],
        'unknown team' => ['&team_ids[]={team}&team_ids[]=999999', 'team_ids.1', 'Tim yang dipilih tidak ditemukan.'],
        'same team twice' => ['&team_ids[]={team}&team_ids[]={team}', 'team_ids.0', 'Tim yang dipilih tidak boleh dobel.'],
    ]);

    it('accepts a period of exactly the maximum length', function () {
        $this->getJson(erUrl([$this->team->id], '', '2029-03-23', '2030-03-23'), erAuth($this->superAdmin))
            ->assertOk();
    });

    it('answers 500 with a generic message when building the report fails', function (string $format) {
        Log::spy();
        $this->mock(EvaluationReportService::class)
            ->shouldReceive('build')
            ->andThrow(new RuntimeException('db down'));

        $this->getJson(erUrl([$this->team->id], $format), erAuth($this->superAdmin))
            ->assertStatus(500)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Gagal membuat laporan evaluasi.');

        Log::shouldHaveReceived('error')->once();
    })->with([
        'preview' => [''],
        'pdf' => ['/pdf'],
    ]);
});

describe('GET /super-admin/reports/evaluation/pdf', function () {
    it('downloads the report as a PDF named after team and period', function () {
        $alice = $this->createEmployee(['name' => 'Alice']);
        $project = Project::factory()->create(['team_id' => $this->team->id, 'name' => 'Santri Link']);
        erTask(erSession($alice, 18), $project, 'Fixing WA Notif');

        $response = $this->get(erUrl([$this->team->id], '/pdf'), erAuth($this->superAdmin));

        $response->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')
            ->assertHeader('Content-Disposition', 'attachment; filename="laporan-evaluasi-it-development-2030-03-18-sd-2030-03-23.pdf"');

        // Cover, staff summary, one staff table, work summary and one project table page.
        expect($response->getContent())->toStartWith('%PDF-')
            ->and(erPageCount($response->getContent()))->toBe(5);
    });

    it('names the file after the number of teams when several are combined', function () {
        $desain = $this->createTeam(['name' => 'Desain']);

        $response = $this->get(erUrl([$this->team->id, $desain->id], '/pdf'), erAuth($this->superAdmin));

        $response->assertOk()
            ->assertHeader('Content-Disposition', 'attachment; filename="laporan-evaluasi-gabungan-2-tim-2030-03-18-sd-2030-03-23.pdf"');
        expect($response->getContent())->toStartWith('%PDF-');
    });

    it('puts the custom title into the PDF', function () {
        $default = $this->get(erUrl([$this->team->id], '/pdf'), erAuth($this->superAdmin));
        $custom = $this->get(erUrl([$this->team->id], '/pdf', title: 'Evaluasi Kinerja Triwulan I'), erAuth($this->superAdmin));

        $custom->assertOk()->assertHeader('Content-Type', 'application/pdf');

        expect(erPdfTitle($default->getContent()))->toStartWith('Laporan Evaluasi DIVISI IT DEVELOPMENT - ')
            ->and(erPdfTitle($custom->getContent()))->toStartWith('Laporan Evaluasi Evaluasi Kinerja Triwulan I - ');
    });

    it('exposes the file name to the browser app', function () {
        config(['cors.allowed_origins' => ['http://localhost:10002']]);

        $this->get(erUrl([$this->team->id], '/pdf'), erAuth($this->superAdmin) + ['Origin' => 'http://localhost:10002'])
            ->assertOk()
            ->assertHeader('Access-Control-Expose-Headers', 'Content-Disposition');
    });
});

describe('PDF renderer', function () {
    it('puts every part of the report into the document', function () {
        $report = erReport(2, [
            ['no' => 1, 'id' => 1, 'name' => 'Santri Link', 'pic' => 'Nico', 'progress' => 70, 'problem' => 'Fixing <b>WA</b> Notif', 'note' => 'Proses QA', 'status' => 'on_progress', 'tasks_count' => 1],
        ]);

        $html = app(EvaluationReportPdf::class)->html($report);

        expect($html)
            ->toContain('LAPORAN EVALUASI')
            ->toContain('DIVISI IT DEVELOPMENT')
            ->toContain('Periode: 18 – 23 Maret 2030')
            ->toContain('TOTAL SDM: 2 KARYAWAN')
            ->toContain('KARYAWAN TETAP: 2 ORANG')
            ->toContain('KARYAWAN INTERN: 0 ORANG')
            ->toContain('PERSENTASE KEHADIRAN: 97,5%')
            ->toContain('Karyawan Nomor 2')
            ->toContain('88 Jam 5 Menit')
            ->toContain('<td style="color: #09963b;">Sangat Baik</td>')
            ->toContain('Ringkasan Laporan Pekerjaan')
            ->toContain('Santri Link')
            ->toContain('Proses QA')
            // Employee input is escaped, never interpreted as markup.
            ->toContain('Fixing &lt;b&gt;WA&lt;/b&gt; Notif')
            ->not->toContain('Belum ada project.');

        // Exactly one progress column is ticked.
        expect(substr_count($html, '<span class="check">'))->toBe(1);
    });

    it('prints a custom title on the cover as typed and escapes it', function () {
        $html = app(EvaluationReportPdf::class)->html(erReport(0, [], 'Evaluasi <b>Q1</b> & Rencana'));

        expect($html)
            ->toContain('>Evaluasi &lt;b&gt;Q1&lt;/b&gt; &amp; Rencana</div>')
            ->not->toContain('DIVISI IT DEVELOPMENT')
            ->not->toContain('<b>Q1</b>');
    });

    it('uses only bundled JPEG images, which need no GD extension on the server', function () {
        $html = app(EvaluationReportPdf::class)->html(erReport(1));

        preg_match_all('/<img[^>]+src="([^"]+)"/', $html, $matches);
        $sources = array_unique($matches[1]);

        // Cover, three brush strokes and four tiles. dompdf can only embed PNG files through GD.
        expect($sources)->toHaveCount(8);

        foreach ($sources as $source) {
            expect($source)->toEndWith('.jpg')
                ->and(is_file($source))->toBeTrue();
        }
    });

    it('formats the attendance rate and handles an empty team', function () {
        $html = app(EvaluationReportPdf::class)->html(erReport());

        expect($html)
            ->toContain('TOTAL SDM: 0 KARYAWAN')
            ->toContain('PERSENTASE KEHADIRAN: -')
            ->toContain('Belum ada project.')
            // One staff slide even without staff, padded with empty rows.
            ->and(substr_count($html, 'Laporan SDM</div>'))->toBe(1)
            ->and(substr_count($html, '<tr class="odd">') + substr_count($html, '<tr class="even">'))->toBe(EvaluationReportPdf::STAFF_ROWS_PER_PAGE);

        $whole = erReport(1);
        $whole['human_resources']['summary']['attendance_percentage'] = 100.0;

        expect(app(EvaluationReportPdf::class)->html($whole))->toContain('PERSENTASE KEHADIRAN: 100%');
    });

    it('splits the staff table into slides of ten rows', function () {
        $pdf = app(EvaluationReportPdf::class);
        $html = $pdf->html(erReport(21));

        expect(substr_count($html, 'Laporan SDM</div>'))->toBe(3)
            ->and(substr_count($html, '<tr class="odd">') + substr_count($html, '<tr class="even">'))->toBe(30);

        // Cover + staff summary + 3 staff slides + work summary + project table.
        expect(erPageCount($pdf->render(erReport(21))))->toBe(7);
    });

    it('shrinks the cover title for long team names', function () {
        $pdf = app(EvaluationReportPdf::class);

        expect($pdf->html(erReport()))->toContain('font-size: 86pt;">DIVISI IT DEVELOPMENT')
            ->and($pdf->html(erReport(0, [], 'DIVISI PENGEMBANGAN SISTEM INFORMASI')))->toContain('font-size: 55pt;">DIVISI PENGEMBANGAN SISTEM INFORMASI')
            ->and($pdf->html(erReport(0, [], 'DIVISI '.str_repeat('SANGAT PANJANG ', 8))))->toContain('font-size: 28pt;">DIVISI SANGAT PANJANG');
    });

    it('lets a long project table flow onto further pages', function () {
        $rows = [];
        for ($i = 1; $i <= 60; $i++) {
            $rows[] = ['no' => $i, 'id' => $i, 'name' => "Project {$i}", 'pic' => 'Tim', 'progress' => ($i % 11) * 10, 'problem' => 'Tidak ada', 'note' => 'On-Progress', 'status' => 'on_progress', 'tasks_count' => 0];
        }

        // 4 designed slides (no staff: one padded table) + 3 pages of project rows.
        expect(erPageCount(app(EvaluationReportPdf::class)->render(erReport(0, $rows))))->toBe(7);
    });
});
