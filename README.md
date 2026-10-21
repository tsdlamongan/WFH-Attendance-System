# WFH Attendance & Task Tracking System

<div align="center">

![Status](https://img.shields.io/badge/Status-Production%20Ready-success)
![Version](https://img.shields.io/badge/Version-1.2.0-blue)
![Backend](https://img.shields.io/badge/Backend-Laravel%2012-red)
![Frontend](https://img.shields.io/badge/Frontend-React%2019-blue)
![Database](https://img.shields.io/badge/Database-PostgreSQL-blue)
![Security](https://img.shields.io/badge/Security-reCAPTCHA%20v2-green)
![Tests](https://img.shields.io/badge/Tests-171%20Passed-success)

**Modern, Full-Stack Employee Attendance & Task Tracking System for Remote Work**

[Quick Start](#-quick-start) • [Features](#-features) • [Documentation](#-documentation) • [Testing](#-testing) • [Deployment](#-deployment)

</div>

---

## 📋 Overview

Sistem manajemen kehadiran dan tracking task untuk karyawan Work From Home (WFH) yang lengkap dengan fitur:

### 🔐 Advanced Security & Authentication
- ✅ Google reCAPTCHA v2 integration
- ✅ **NEW**: Registration control (enable/disable via environment)
- ✅ **NEW**: Enhanced password management
- ✅ Laravel Sanctum authentication
- ✅ Multi-layer security protection

### 👥 Multi-Tenant Team Management
- ✅ **NEW**: Team-based data isolation
- ✅ **NEW**: Customizable team settings
- ✅ **NEW**: Super admin features
- ✅ **NEW**: User impersonation for support
- ✅ Cross-team user management

### 🏢 Complete Attendance System
- ✅ Check-in/Check-out dengan task management
- ✅ Keyboard shortcuts & multi-line paste support
- ✅ Expandable task details in reports
- ✅ Enhanced approve/reject buttons with animations
- ✅ Installment system (multiple sessions per day)
- ✅ Real-time progress tracking (7 jam kerja)

### 📊 Advanced Management Features
- ✅ Leave management dengan approval workflow
- ✅ Overtime (lembur) approval: pengajuan → persetujuan manager, durasi menjadi batas keras
- ✅ Holiday management per team
- ✅ Daftar project per tim (dikelola manager) yang dipilih karyawan saat check-in
- ✅ Laporan Evaluasi PDF (super admin): rekap jam kerja SDM + laporan pekerjaan per project, satu atau beberapa tim digabung jadi satu laporan
- ✅ Comprehensive reporting & analytics
- ✅ Activity logging untuk audit trail
- ✅ Role-based access control (Super Admin, Manager, Employee)

---

## 🏗️ Architecture

### Backend
- **Framework**: Laravel 13
- **Database**: PostgreSQL
- **Authentication**: Laravel Sanctum
- **API**: RESTful API with versioning
- **PDF**: dompdf (evaluation report)
- **Testing**: Pest 4 / PHPUnit 12 (1,687 tests, 6,797 assertions, ≥90% line coverage enforced)

### Frontend
- **Framework**: React 19+ with Vite
- **Styling**: TailwindCSS
- **Routing**: React Router v7
- **State Management**: React Context API
- **HTTP Client**: Axios
- **Security**: Google reCAPTCHA v2
- **UI Components**: Custom + Lucide Icons

---

## 🚀 Quick Start

### Prerequisites
- PHP 8.3+, Composer
- Node.js 20.19+ (or 22.12+), npm
- PostgreSQL 15+

### Installation (5 minutes)

**1. Backend Setup:**
```bash
cd backend
composer install
cp .env.example .env
# Configure database in .env
php artisan key:generate
php artisan migrate:fresh --seed
```

**2. Frontend Setup:**
```bash
cd frontend
npm install
cp .env.example .env
```

**3. Run the app** (from the repository root; both servers read their `.env`):
```bash
make up      # backend → http://localhost:10001, frontend → http://localhost:10002 (Ctrl+C stops both)
make down    # stop the servers and clear leftover caches
make help    # list every target
```

**4. Login Credentials:**
- Super Admin: `admin@example.com` / `password123`

**5. Configuration (Optional):**
```bash
# Backend (.env)
RECAPTCHA_SECRET_KEY=your_google_recaptcha_secret_key
ENABLE_REGISTRATION=true

# Frontend (.env)
VITE_RECAPTCHA_SITE_KEY=your_google_recaptcha_site_key
VITE_ENABLE_REGISTRATION=true
```

---

## ✨ Features

### 🔐 Authentication & Security
- **NEW**: Google reCAPTCHA v2 protection
- **NEW**: Registration control via environment variables
- Secure login with Laravel Sanctum
- **NEW**: Enhanced password management
- Role-based access control (Super Admin, Manager, Employee)
- Auto-logout on token expiration
- Protected routes with role checking
- **NEW**: Multi-layer security protection

### 👨‍💼 Employee Features

#### ⏰ Attendance Management
- **Check-in** with task planning (1-20 tasks)
- **Check-out** with task completion status
- **Installment System**: Multiple check-in/out per day
- **Real-time Tracking**: Live hours counter
- **Progress Bar**: Visual 7-hour requirement tracker
- **Blocker Reporting**: Document incomplete task reasons
- **Project per Task**: Pick the team's project for every task at check-in
- **Standby Detection**: A task that mentions "standby" (also "stand by", "stand-by", "stanby", …) marks the check-in as standby and notifies the manager over WhatsApp
- **Repeated Task Alert**: Checking in with the same unfinished task 3 times in a row sends the manager a WhatsApp request to review it

#### 📊 Work Reports
- Daily, weekly, monthly statistics
- Task completion rate tracking
- Overtime hours calculation
- Date range filtering
- Session breakdown view

#### 🏖️ Leave Management
- Submit leave requests
- Track request status (pending/approved/rejected)
- View manager notes
- Leave history

### 👔 Manager Features

#### 📈 Team Dashboard
- Real-time team overview
- Employee status monitoring (checked-in/out/on-leave)
- Daily/weekly/monthly hours tracking
- Average team performance metrics
- Date-based filtering

#### 👥 User Management
- Create/Edit/Delete users
- Role assignment (Employee/Manager)
- Password management
- User activity tracking

#### 📅 Holiday Management
- Add company holidays
- Year-based organization
- Holiday descriptions
- Automatic check-in blocking

#### 📁 Project Management
- Maintain the team's project list (name, PIC, progress, status, note)
- Change progress and status straight in the table
- Deactivate a project to hide it from the check-in form (history is kept)

#### ✅ Leave Approval
- Review leave requests
- Approve/Reject with notes
- Status filtering
- Employee details view

#### 📝 Activity Logs
- Complete audit trail
- Filter by user, action, date
- IP address tracking
- Detailed action descriptions
- **NEW**: Impersonation tracking

### 🌟 Super Admin Features

#### 🏢 Multi-Team Management
- **NEW**: Manage all teams across the system
- **NEW**: Create/delete teams with settings
- **NEW**: Cross-team user management
- **NEW**: Team settings configuration

#### 📄 Evaluation Report (PDF)
- Menu "Laporan Evaluasi" (`/super-admin/evaluation-report`): pick the teams and the period first, nothing is generated until "Tampilkan" or "Download PDF" is clicked
- One or more teams are merged into one report: one staff list, one project list, one cover title ("DIVISI A & B")
- Report for any period in the team's slide format: cover, staff summary, staff table, work summary, project table
- On-screen preview of the data before downloading
- Staff hours and rating come from the check-in data, the work per project from the tasks employees logged

#### 👤 User Impersonation
- **NEW**: Impersonate any user for support
- **NEW**: Secure session handling
- **NEW**: Activity tracking during impersonation
- **NEW**: Easy stop impersonation functionality

#### 📊 System Overview
- **NEW**: Global system statistics
- **NEW**: Cross-team reporting
- **NEW**: System administration tools
- **NEW**: Advanced user management

### 🎨 UI/UX Features
- **Responsive Design**: Mobile, tablet, desktop optimized
- **Modern UI**: Clean, professional interface
- **Real-time Updates**: Live data refresh
- **Toast Notifications**: User-friendly feedback
- **Loading States**: Smooth loading indicators
- **Error Handling**: Graceful error messages
- **Accessibility**: Keyboard navigation support
- **Color-coded Status**: Visual status indicators

---

## 📊 System Statistics

### Backend
- **1,687 Tests** - All passing ✅
- **6,797 Assertions** - 100% line coverage (minimum standard: 90%)
- **35+ API Endpoints** - Fully documented
- **25+ Features** - Complete implementation
- **0 Known Bugs** - Production ready

### Frontend
- **50+ Source Files** - Well organized
- **25+ Components** - Reusable & modular
- **15+ Pages** - Complete user flows
- **12+ API Modules** - Clean architecture
- **100% Feature Coverage** - All requirements met
- **NEW**: reCAPTCHA integration
- **NEW**: Super admin interface
- **NEW**: Enhanced user management

---

## 📚 Documentation

### Main Documentation
| Document | Description |
|----------|-------------|
| [FEATURES.md](FEATURES.md) | Complete feature specifications |
| [PROJECT_STRUCTURE.md](PROJECT_STRUCTURE.md) | Architecture & structure details |
| [CODING_STANDARDS.md](CODING_STANDARDS.md) | Code standards & best practices |

---

## 🧪 Testing

### Make targets (repository root)

Every check runs from the single root `Makefile` and uses the committed test environments `backend/.env.testing` + `frontend/.env.testing`, never your local `.env` (Laravel loads `.env.testing` instead of `.env` when `APP_ENV=testing`; Vite layers `frontend/.env.testing` over `.env` with `--mode testing`):

```bash
make test                                        # lint → backend suite → frontend E2E
make cover                                       # backend suite + coverage table (fails below 90% lines)
make lint                                        # Pint --test + ESLint, no files changed
make format                                      # Pint + ESLint --fix
make test-backend ARGS="--filter=AttendanceTest" # backend only
make test-e2e ARGS="tests/e2e/auth.spec.js"      # E2E only
```

### Backend Testing

The backend uses [Pest](https://pestphp.com) 4 on top of PHPUnit 12. Run it through `php artisan test` (plain `phpunit` cannot load Pest-style tests).

```bash
cd backend
php artisan test                      # run the suite
php artisan test --coverage           # run + coverage report; exits 1 below 90% (built-in guard)
php artisan test --coverage --min=0   # coverage report only, e.g. with --filter on a single test
composer test:coverage                # same as `test --coverage`, plus clearing the config cache
```

**Coverage standard: at least 90% line coverage, always.** `php artisan test --coverage` enforces it by itself (`backend/app/Console/Commands/TestCommand.php`): the run fails below 90% unless you pass an explicit `--min`. Every new feature or bug fix ships with the tests that keep the backend at or above 90%. Call `php artisan test`, not `./vendor/bin/pest --coverage` directly: Pest's own runner has no default minimum, so it would only print the number. Write new tests Pest-style (`it('...', function () {...})`, see `backend/tests/Pest.php`); the existing class-based PHPUnit tests keep working.

Coverage needs a driver. [PCOV](https://github.com/krakjoe/pcov) is recommended (fast, coverage only): `pecl install pcov` on Linux/macOS, or enable `extension=php_pcov.dll` from the PECL build on Windows. Xdebug in `coverage` mode also works.

**Results:**
- ✅ 1,687 tests passed (6,797 assertions)
- ✅ 100% line coverage (gate: 90%)
- ✅ 0 failures

**Coverage:**
- Authentication (12 tests)
- Attendance Management (11 tests)
- Task Management (9 tests)
- Leave Management (15 tests)
- User Management (12 tests)
- Holiday Management (10 tests)
- Activity Logs (9 tests)
- Manager Features (45 tests)
- **NEW**: Super Admin Features (15 tests)
- **NEW**: reCAPTCHA & Security (8 tests)
- **NEW**: Registration Control (5 tests)

### Frontend Testing

**End-to-end (Playwright):** `make test-e2e` (or `npm test` in `frontend/`) boots a real stack: it frees ports 10003/10004, creates a fresh SQLite database as configured in `backend/.env.testing`, runs `migrate:fresh` + `E2ESeeder`, starts the backend on :10003 and Vite (`--mode testing`, loading `frontend/.env.testing`) on :10004, then runs the specs in `frontend/tests/e2e/` with one worker. Seeded users (`superadmin@e2e.test`, `manager.alpha@e2e.test`, `employee.alpha1@e2e.test`, … password `Password1!`) span two teams to exercise team isolation.

```bash
make test-e2e                                   # whole suite
make test-e2e ARGS="tests/e2e/auth.spec.js"     # one spec
cd frontend && npm run test:headed              # also: test:ui | test:debug | test:report
```

**Manual Testing:**
Comprehensive testing covers authentication, employee features, manager features, UI/UX, integration testing, edge cases, browser compatibility, and security testing.

**Test Categories:**
1. Authentication Testing (7 test cases)
2. Employee Features (25+ test cases)
3. Manager Features (30+ test cases)
4. UI/UX Testing (15+ test cases)
5. Integration Testing (10+ test cases)
6. Edge Cases (10+ test cases)
7. Browser Compatibility
8. Security Testing

---

## 🎯 Business Logic

### Work Hours Rules
- **Required Hours**: 7 hours per day
- **Installment System**: Multiple sessions allowed
- **Overtime Tracking**: Hours > 7 recorded
- **Manual Checkout**: Employees must checkout manually
- **Cross-Day Sessions**: Can check-in at night and checkout next day
- **No Buffer**: Exactly 7 hours required

### Task Management
- **Minimum**: 1 task per check-in
- **Maximum**: 20 tasks per check-in
- **Add During Session**: Yes
- **Blocker Required**: For incomplete tasks
- **Max Blocker Length**: 500 characters
- **Standby**: A check-in is in standby mode when any task title contains a standby-like word (`Task::STANDBY_PATTERN`: "standby", "stand by", "stand-by", "stand_by", "standbye", "stanby", "standbay", anywhere in the title; "standard" or "standing" do not count). The check-in form ticks its Standby checkbox automatically, the task carries no project, the API returns `is_standby` on the session and on each task, and the team's WhatsApp recipient gets the standby notification (activity `whatsapp_standby_sent`). The pattern lives in `backend/app/Models/Task.php` and `frontend/src/utils/standby.js`; keep both in sync
- **Repeated Task Alert**: When an employee checks in with a task that was also carried, and left incomplete, on each of the previous days they checked in, the 3rd consecutive day (`REPEATED_TASK_ALERT_THRESHOLD`, default 3) sends the team's WhatsApp recipient a "Tugas Perlu Review" message listing the task, how many days in a row it has been carried and the latest blocker the employee wrote (activity `whatsapp_task_review_sent`). While the streak goes on the reminder repeats at every further multiple (6th, 9th, …). Days count, not check-ins, so several sessions on one day count once and three check-ins on the same day never trigger it; only days with a check-in count, so weekends or days off in between do not break the streak; a previous day counts when the task was left open in that day's latest session that carried it, while a day on which it was completed or not carried at all breaks the streak; titles are compared ignoring case and whitespace; standby tasks are never counted; only the last 30 days are inspected

### Leave Rules
- **Advance Request**: Required
- **Manager Approval**: Mandatory
- **Check-in Blocked**: During approved leave
- **Reason Required**: Minimum 10 characters

### Overtime Rules
- **Overtime Session**: A check-in made after the day's required hours are met
- **Request**: Reason (min. 5 chars) + duration (15-480 minutes), sent with the check-in or in advance (today only); status starts as `pending`
- **Soft Gate**: Employee may check in while `pending`; the hours count as overtime in reports only once `approved`
- **Rejected**: Further overtime check-in that day is blocked (a manager can assign overtime again); a running session is closed on the next auto-checkout tick
- **Hard Cap**: Auto-checkout closes the session when the allowed minutes (`approved_minutes`, else requested) are used up
- **Manager**: Approves (optionally with a different duration), rejects (note required), or assigns overtime (auto-approved) for employees of the own team
- **Notification**: WhatsApp message to the team recipient on a new request (same gateway as the standby alert)
- **Go-live Date**: Days before `OVERTIME_APPROVAL_STARTS_ON` (default `2026-10-05`) keep the legacy report rule (every hour above the required hours counts as overtime)

### Project Rules
- **Managed by**: Manager, per team (name unique in the team, progress 0-100% in steps of 10, status On-Progress / Selesai / Hold)
- **At Check-in**: When the team has active projects, every task needs a project or the explicit "Lainnya (di luar project)" choice; a Standby task carries no project
- **Inactive Project**: No longer offered at check-in, its history stays; a project that tasks refer to cannot be deleted
- **Manager Tools**: Manual attendance and task correction may use any project of the team, inactive ones included

### Evaluation Report Rules
- **Access**: Super admin only (`GET /api/v1/super-admin/reports/evaluation` and `/pdf` with `team_ids[]`, `start_date`, `end_date`); managers no longer have it
- **Teams**: At least one, any team (inactive ones included). Several teams are merged: staff of all teams in one list, projects team by team (teams ordered by name, same as the cover title). The PDF is named after the team slug, or `gabungan-N-tim` for a combined report
- **Staff List**: Managers first (they do not check in, so no hours), then permanent employees, then interns; job title (Jabatan) and employment type are set in user management
- **Jumlah Jam Kerja**: Same total as the monthly attendance report (an approved leave day is credited 8 hours)
- **Status**: Worked hours against the expected hours of the person's own team (working days × 8, holidays are per team): ≥ 100% Sangat Baik, ≥ 90% Baik, below that Kurang Baik (`backend/config/evaluation_report.php`)
- **Persentase Kehadiran**: Days worked or on approved leave ÷ working days, over all employees of all selected teams
- **Projects Listed**: Active projects plus inactive ones that had tasks in the period
- **PIC**: The project's PIC; when empty, the employees who logged tasks for it in the period
- **UPDATE / NOTE**: Progress and note of the project (the note falls back to the status)
- **PROBLEM**: The distinct task titles employees logged for the project in the period; a task left open at check-out carries its blocker; "Tidak ada" when nothing was logged
- **PROBLEM Summary (optional)**: With `GEMINI_API_KEY` set (Google AI Studio, free tier is enough for a weekly report), entries of 120+ characters are shortened to one or two sentences by Gemini (`GEMINI_MODEL`, default `gemini-2.5-flash`). One request covers the whole report, results are cached for 7 days, and on any API error the plain list is printed. The preview shows the original list under each summary. Switch off with `EVALUATION_REPORT_AI_SUMMARY=false`
- **Period**: Required, at most 366 days
- **PDF**: Rendered with dompdf from `backend/resources/views/reports/evaluation.blade.php`. Images in `backend/resources/reports/evaluation` are JPEG on purpose (dompdf needs the GD extension for PNG); the font cache is written to `backend/storage/app/private/dompdf`, so `storage/` must be writable

### Holiday Rules
- **Manager Only**: Can create/edit
- **Check-in Blocked**: On holidays
- **Excluded from**: Required work hours

---

## 🔐 Security Features

### Backend
- ✅ Laravel Sanctum authentication
- ✅ **NEW**: Google reCAPTCHA v2 protection
- ✅ CSRF protection
- ✅ SQL injection prevention (Eloquent ORM)
- ✅ Mass assignment protection
- ✅ Password hashing (bcrypt)
- ✅ Input validation & sanitization
- ✅ Rate limiting
- ✅ Activity logging
- ✅ **NEW**: Multi-layer registration control
- ✅ **NEW**: Team-based data isolation
- ✅ **NEW**: Secure impersonation handling

### Frontend
- ✅ Token-based authentication
- ✅ **NEW**: Google reCAPTCHA v2 integration
- ✅ Auto-logout on 401
- ✅ XSS prevention (React escaping)
- ✅ Protected routes
- ✅ Role-based access control
- ✅ Secure token storage
- ✅ **NEW**: Registration control
- ✅ **NEW**: Protected registration routes

---

## 🚀 Deployment

### Development
```bash
make up      # backend (php artisan serve :10001) + frontend (vite :10002), from the repo root
make down    # stop both and clear leftover caches
```

### Production

**Option 1: VPS (Recommended)**
- Includes Nginx, SSL, PostgreSQL setup
- Cron job configuration for scheduled tasks
- After every release: `composer install --no-dev` and `php artisan migrate --force` in `backend/`

**Option 2: Cloud**
- Frontend: Vercel/Netlify
- Backend: Railway/Heroku
- Database: Managed PostgreSQL

📖 **Deployment**: Backend requires VPS with PHP 8.3+, PostgreSQL 15+, Nginx/Apache, SSL certificate

---

## 📁 Project Structure

```
WFH-Attendance-System/
├── README.md                  # Main Project Documentation
├── FEATURES.md                # Complete Feature Specifications
├── PROJECT_STRUCTURE.md       # Architecture & Structure Details
├── CODING_STANDARDS.md        # Code Standards & Best Practices
│
├── backend/                   # Laravel 13 API
│   ├── app/
│   │   ├── Http/Controllers/  # API Controllers
│   │   ├── Models/            # Eloquent Models
│   │   ├── Services/          # Business Logic
│   │   └── Repositories/      # Data Access Layer
│   ├── database/
│   │   ├── migrations/        # Database Migrations
│   │   └── seeders/           # Data Seeders
│   ├── routes/
│   │   ├── api.php           # API Routes
│   │   └── web.php           # Web Routes
│   ├── tests/                # Pest Tests (1,687 tests, ≥90% coverage)
│   ├── config/               # Configuration Files
│   ├── public/               # Public Assets
│   └── resources/            # Views & Resources
│
├── frontend/                  # React 19+ Application
│   ├── src/
│   │   ├── api/              # API Service Functions
│   │   ├── components/       # React Components
│   │   │   ├── common/       # Reusable UI Components
│   │   │   ├── layout/       # Layout Components (Navbar, Sidebar)
│   │   │   └── attendance/   # Attendance Feature Components
│   │   ├── contexts/         # React Contexts (Auth, etc.)
│   │   ├── hooks/            # Custom React Hooks
│   │   ├── pages/            # Page Components
│   │   │   ├── auth/         # Authentication Pages
│   │   │   ├── employee/     # Employee Dashboard & Pages
│   │   │   ├── manager/      # Manager Dashboard & Pages
│   │   │   └── LandingPage.jsx # Landing Page
│   │   ├── utils/            # Utility Functions
│   │   ├── index.css         # TailwindCSS 4 theme tokens (@theme) & component classes
│   │   ├── App.jsx           # Main Application Component
│   │   └── main.jsx          # Application Entry Point
│   ├── public/               # Static Assets (PWA files, robots.txt)
│   ├── dist/                 # Production Build Output
│   ├── index.html            # HTML Template with SEO optimization
│   ├── package.json          # Dependencies & Scripts
│   └── vite.config.js        # Vite Configuration (incl. @tailwindcss/vite plugin)
│
└── .git/                     # Git Repository
```

---

## 🛠️ Technology Stack

### Backend Stack
| Technology | Version | Purpose |
|-----------|---------|---------|
| Laravel | 13 | PHP Framework |
| PostgreSQL | 15+ | Database |
| Sanctum | 4.3 | Authentication |
| dompdf | 3.1 | PDF reports |
| Pest | 4 | Testing (on PHPUnit 12, coverage via PCOV) |

### Frontend Stack
| Technology | Version | Purpose |
|-----------|---------|---------|
| React | 19+ | UI Framework |
| Vite | Latest | Build Tool |
| TailwindCSS | 4 | Styling (browsers: Chrome 111+, Safari 16.4+, Firefox 128+) |
| React Router | v7 | Routing |
| Axios | Latest | HTTP Client |
| date-fns | Latest | Date Handling |
| **NEW** | reCAPTCHA v2 | Security Protection |
| **NEW** | Custom Hooks | State Management |

---

## 📈 Performance

### Backend
- ✅ Optimized queries with eager loading
- ✅ Database indexing
- ✅ Route caching
- ✅ Config caching
- ✅ OPcache enabled

### Frontend
- ✅ Code splitting
- ✅ Lazy loading
- ✅ Optimized re-renders
- ✅ TailwindCSS purging
- ✅ Asset optimization

---

## 🌐 Browser Support

| Browser | Version | Status |
|---------|---------|--------|
| Chrome | Latest | ✅ Fully Supported |
| Firefox | Latest | ✅ Fully Supported |
| Safari | Latest | ✅ Fully Supported |
| Edge | Latest | ✅ Fully Supported |

---

## 🤝 Contributing

### Development Workflow
1. Read [CODING_STANDARDS.md](CODING_STANDARDS.md)
2. Create feature branch
3. Write tests
4. Implement feature
5. `make format`, then `make test` (lint → backend → E2E) and `make cover` (backend line coverage must stay ≥ 90%)
6. Submit PR

### Code Standards
- Follow PSR-12 (PHP)
- Follow Airbnb style guide (JavaScript)
- Write meaningful commit messages
- Add comments for complex logic
- Update documentation

---

## 📞 Support

### Documentation
- Check main [README.md](README.md) for overview
- Review [FEATURES.md](FEATURES.md) for complete feature specifications
- See [PROJECT_STRUCTURE.md](PROJECT_STRUCTURE.md) for architecture details

### Troubleshooting
1. Check console for errors (F12)
2. Review backend logs: `backend/storage/logs/laravel.log`
3. Verify environment variables
4. Check database connection
5. Ensure all services running

---

## 📝 License

This project is proprietary software. All rights reserved.

---

## 🎉 Acknowledgments

- Laravel Team for amazing framework
- React Team for powerful UI library
- TailwindCSS for beautiful styling
- All contributors and testers

---

## 📊 Project Status

<div align="center">

### ✅ PRODUCTION READY

| Component | Status | Tests | Coverage |
|-----------|--------|-------|----------|
| Backend API | ✅ Complete | 1641/1641 | 100% |
| Frontend UI | ✅ Complete | Manual | 100% |
| Security | ✅ Complete | All Tested | 100% |
| Super Admin | ✅ Complete | All Tested | 100% |
| Documentation | ✅ Complete | N/A | 100% |
| Testing Guide | ✅ Complete | 100+ cases | 100% |
| Deployment | ✅ Ready | Tested | 100% |

**Last Updated**: 2025-11-09
**Version**: 1.2.0 (Advanced Security & Admin Features)
**Status**: Production Ready 🚀

</div>

---

## 🚀 Getting Started

Ready to start? Follow these steps:

1. 📖 Read installation instructions above - Get running in 5 minutes
2. 🧪 Test all features using the provided test credentials
3. 🚀 Deploy to your preferred hosting platform

---

<div align="center">

**Made with ❤️ for Remote Work Management**

[⬆ Back to Top](#wfh-attendance--task-tracking-system)

</div>
