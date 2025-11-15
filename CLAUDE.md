# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

WFH (Work From Home) Attendance & Task Tracking System - A production-ready, full-stack application for managing employee attendance and task tracking with multi-tenant team support.

**Architecture**: Separated Laravel 12 backend API + React 19 frontend (SPA)
**Version**: 1.2.0 (Advanced Security & Admin Features)
**Database**: PostgreSQL 15+

## Essential Reading

Before making any changes, **ALWAYS read these files first**:
1. `CODING_STANDARDS.md` - Mandatory coding standards and patterns
2. `PROJECT_STRUCTURE.md` - Architecture and file organization
3. `FEATURES.md` - Complete feature specifications and API contracts

This is critical - the `.cursorrules` file enforces this requirement.

## Development Commands

### Backend (Laravel)

```bash
# Navigate to backend
cd backend

# Install dependencies
composer install

# Setup environment
cp .env.example .env
php artisan key:generate

# Database operations
php artisan migrate           # Run migrations
php artisan migrate:fresh     # Fresh migration (drops all tables)
php artisan migrate:fresh --seed  # Fresh migration + seed data

# Start development server
php artisan serve            # Runs on http://localhost:8000

# Run tests (171 tests, 688 assertions)
php artisan test             # Run all tests
php artisan test --filter=AttendanceTest  # Run specific test file
php artisan test --testsuite=Feature      # Run feature tests only

# Clear caches
php artisan config:clear
php artisan cache:clear
php artisan route:clear

# Cron job (auto-checkout at 23:59)
php artisan attendance:auto-checkout

# Code formatting (Laravel Pint)
./vendor/bin/pint

# Watch logs
php artisan pail
```

### Frontend (React + Vite)

```bash
# Navigate to frontend
cd frontend

# Install dependencies
npm install

# Start development server
npm run dev              # Runs on http://localhost:5173

# Build for production
npm run build

# Preview production build
npm run preview

# Lint code
npm run lint
```

### Concurrent Development

From backend directory:
```bash
composer run dev  # Runs Laravel + Queue + Logs + Vite concurrently
```

## Architecture Patterns

### Backend (Repository-Service Pattern)

**Flow**: Controller → Service → Repository → Model

1. **Controllers** (`app/Http/Controllers/Api/`)
   - Handle HTTP requests/responses only
   - Validate using FormRequest classes
   - Delegate business logic to Services
   - Return JSON responses with Resources

2. **Services** (`app/Services/`)
   - Contain ALL business logic
   - Orchestrate multiple repository calls
   - Handle complex calculations
   - Log activities
   - Example: `AttendanceService::checkIn()` handles check-in logic, creates tasks, logs activity

3. **Repositories** (`app/Repositories/`)
   - Data access layer ONLY
   - No business logic
   - Query database using Eloquent
   - Example: `AttendanceRepository::findByUserAndDate()`

4. **Form Requests** (`app/Http/Requests/`)
   - Validation rules
   - Authorization logic
   - Custom error messages

5. **Resources** (`app/Http/Resources/`)
   - Transform models to JSON
   - Control API response structure
   - Handle relationship loading

### Frontend (Component-Context-API Pattern)

**Flow**: Component → Custom Hook → API Module → Axios

1. **Pages** (`src/pages/`)
   - Route-level components
   - Organize by role: `auth/`, `employee/`, `manager/`, `super-admin/`

2. **Components** (`src/components/`)
   - Reusable UI components
   - Organized by feature or type
   - Use composition over inheritance

3. **Contexts** (`src/contexts/`)
   - Global state (e.g., `AuthContext`)
   - Provide authentication state across app

4. **Custom Hooks** (`src/hooks/`)
   - Encapsulate reusable logic
   - Example: `useAuth()`, `useAttendance()`

5. **API Modules** (`src/api/`)
   - One file per domain (auth, attendance, task, etc.)
   - All API calls centralized
   - Use shared axios instance

## Key Business Logic

### Work Hours System
- **Required**: 7 hours per day (no tolerance)
- **Installment System**: Multiple check-in/checkout sessions per day are allowed
  - Example: 9-12 (3h) + 14-18 (4h) = 7h total ✓
- **Auto-checkout**: System automatically checks out users at 23:59 if they forgot
- **Overtime**: Hours beyond 7 are tracked separately

### Multi-Tenant Team Isolation
- Each team has isolated data (users, attendances, holidays, activity logs)
- `EnsureTeamAccess` middleware enforces team boundaries
- Super Admin can bypass team restrictions
- Manager can only access their team's data

### Role-Based Access Control
1. **Super Admin** (`UserRole::SUPER_ADMIN`)
   - Manage all teams
   - Cross-team user management
   - Impersonate any user (except other super admins)
   - Bypass team access restrictions

2. **Manager** (`UserRole::MANAGER`)
   - Team dashboard and reports
   - User CRUD within team
   - Edit/delete attendance records
   - Approve/reject leave requests
   - Manage holidays
   - View activity logs

3. **Employee** (`UserRole::EMPLOYEE`)
   - Check-in/checkout
   - Manage own tasks
   - View personal reports
   - Request leave

### Check-In/Check-Out Flow
1. **Check-In**: User must provide 1-20 tasks
2. **During Session**: Can add more tasks
3. **Check-Out**: Must mark each task as completed/incomplete with blocker reason
4. **Multiple Sessions**: Allowed per day (installment system)

## Database Schema Relationships

```
teams
├── users (belongsTo team)
│   ├── attendances (hasMany)
│   │   └── tasks (hasMany)
│   └── leaves (hasMany)
├── holidays (hasMany)
└── activity_logs (hasMany)
```

**Important Indexes**:
- `attendances`: `user_id, date` (composite), `date`
- `tasks`: `attendance_id`
- `leaves`: `user_id`, `status`
- `activity_logs`: `user_id`, `team_id`, `created_at`

## Testing Strategy

### Backend Testing
- **171 tests** covering all features
- Located in `backend/tests/Feature/` and `backend/tests/Unit/`
- Test database: Uses in-memory SQLite
- Always run tests before committing: `php artisan test`

**Test Coverage**:
- Authentication & Authorization
- Attendance check-in/checkout with installments
- Task management
- Leave requests and approval
- User management (CRUD)
- Holiday management
- Activity logging
- Super admin features (teams, impersonation)
- reCAPTCHA validation
- Team isolation

### Frontend Testing
- Manual testing with comprehensive test cases
- Use seeded credentials to test all roles
- Verify reCAPTCHA integration on login/register

## Security Features

### Backend Security
- **Laravel Sanctum**: Token-based SPA authentication
- **Google reCAPTCHA v2**: Protects login/register
- **Team Isolation**: Middleware enforces data boundaries
- **Activity Logging**: All actions tracked with IP, user agent
- **Mass Assignment Protection**: Strict `$fillable` arrays
- **SQL Injection Prevention**: Eloquent ORM with parameter binding
- **Password Hashing**: Bcrypt (never store plain text)

### Frontend Security
- Token stored in `localStorage`
- Axios interceptors handle 401 (auto-logout)
- Protected routes check authentication state
- Role-based component rendering
- XSS prevention via React's automatic escaping

## Environment Variables

### Backend `.env`
```env
DB_CONNECTION=pgsql
DB_DATABASE=wfh_attendance
SANCTUM_STATEFUL_DOMAINS=localhost:5173
SESSION_DOMAIN=localhost
FRONTEND_URL=http://localhost:5173
RECAPTCHA_SECRET_KEY=your_secret_key
ENABLE_REGISTRATION=true
```

### Frontend `.env`
```env
VITE_API_URL=http://localhost:8000/api/v1
VITE_RECAPTCHA_SITE_KEY=your_site_key
VITE_ENABLE_REGISTRATION=true
```

## API Design

### Versioning
- All endpoints prefixed with `/api/v1/`
- Example: `POST /api/v1/attendance/check-in`

### Response Format
```json
{
  "success": true,
  "data": { ... },
  "message": "Operation successful"
}
```

### Error Format
```json
{
  "success": false,
  "message": "Error description",
  "errors": {
    "field": ["Validation error message"]
  }
}
```

### Authentication
- Header: `Authorization: Bearer {token}`
- Middleware: `auth:sanctum`
- Role middleware: `role:manager`, `role:employee`

## Common Development Tasks

### Adding a New Feature
1. Read existing patterns in `CODING_STANDARDS.md`
2. Add migration if database changes needed
3. Create/update Model with relationships
4. Create Repository for data access
5. Create Service for business logic
6. Create FormRequest for validation
7. Create Resource for API response
8. Create Controller method
9. Add route in `routes/api.php`
10. Write tests in `tests/Feature/`
11. Frontend: Create API module, component, hook as needed

### Modifying Attendance Logic
- Business logic lives in `AttendanceService`
- Database queries in `AttendanceRepository`
- Validation in `CheckInRequest` / `CheckOutRequest`
- Always maintain activity logging
- Consider installment system (multiple sessions per day)

### Adding New Validation
- Create FormRequest class: `php artisan make:request YourRequest`
- Define rules in `rules()` method
- Custom messages in `messages()` method
- Use in controller: `public function store(YourRequest $request)`

### Working with Teams
- Always check team access unless super admin
- Use `EnsureTeamAccess` middleware
- Query with team_id: `Attendance::where('team_id', auth()->user()->team_id)`
- Super admin bypasses via `EnsureSuperAdmin` middleware

## Common Pitfalls

1. **N+1 Queries**: Always use eager loading
   ```php
   // ❌ BAD
   $attendances = Attendance::all();
   foreach ($attendances as $attendance) {
       $attendance->tasks; // N+1!
   }

   // ✅ GOOD
   $attendances = Attendance::with('tasks')->get();
   ```

2. **Business Logic in Controllers**: Never!
   ```php
   // ❌ BAD: Logic in controller
   public function checkIn(Request $request) {
       $attendance = Attendance::create([...]);
       foreach ($request->tasks as $task) { ... }
   }

   // ✅ GOOD: Delegate to service
   public function checkIn(CheckInRequest $request) {
       $attendance = $this->attendanceService->checkIn(
           auth()->user(),
           $request->validated()['tasks']
       );
   }
   ```

3. **Forgetting Team Isolation**:
   ```php
   // ❌ BAD: No team filtering
   Attendance::all();

   // ✅ GOOD: Filter by team (unless super admin)
   Attendance::where('team_id', auth()->user()->team_id)->get();
   ```

4. **Not Logging Activities**:
   ```php
   // Always log important actions
   $this->activityLogService->log(
       auth()->user(),
       ActivityType::CHECK_IN,
       'User checked in with ' . count($tasks) . ' tasks'
   );
   ```

5. **Hardcoding Work Hours**: Use team settings
   ```php
   // ❌ BAD
   if ($hours >= 7) { ... }

   // ✅ GOOD
   if ($hours >= $user->team->required_work_hours) { ... }
   ```

## Auto-Checkout System

Implemented via Laravel Scheduler:
- Command: `app/Console/Commands/AutoCheckoutCommand.php`
- Scheduled: Daily at 23:59
- Finds unchecked-out attendances for current date
- Sets check_out to 23:59:59
- Calculates total_hours
- Logs activity

**Setup in production**:
```bash
# Add to crontab
* * * * * cd /path/to/project/backend && php artisan schedule:run >> /dev/null 2>&1
```

## Code Style Enforcement

### Backend
- **Laravel Pint**: Run `./vendor/bin/pint` before committing
- **PSR-12 Standard**: Automatically enforced
- **Naming Conventions**: PascalCase for classes, camelCase for methods

### Frontend
- **ESLint**: Run `npm run lint`
- **File Naming**: PascalCase for components, camelCase for utilities
- **Hooks**: Prefix with `use` (e.g., `useAuth`)

## Git Workflow

### Commit Message Format
```
feat: Add leave approval notifications
fix: Resolve auto-checkout timezone issue
refactor: Extract report calculation to service
docs: Update API documentation
test: Add tests for installment check-in
```

### Before Committing
- [ ] Run backend tests: `php artisan test`
- [ ] Run linter: `./vendor/bin/pint`
- [ ] Check frontend builds: `npm run build`
- [ ] Review changed files
- [ ] Update documentation if needed

## Deployment Notes

### Production Checklist
1. Set `APP_ENV=production` in backend `.env`
2. Run `php artisan config:cache`
3. Run `php artisan route:cache`
4. Run `php artisan view:cache`
5. Set up cron for `schedule:run`
6. Configure Google reCAPTCHA keys
7. Set up PostgreSQL with proper indexing
8. Configure CORS for production domain
9. Set up SSL certificates
10. Enable OPcache for PHP

### Database Migrations
- **Never** modify existing migrations in production
- Create new migrations for changes
- Test migrations on staging first
- Always backup before migrating in production

## Troubleshooting

### Backend Issues
- Check logs: `backend/storage/logs/laravel.log`
- Clear caches: `php artisan config:clear && php artisan cache:clear`
- Check database connection: `php artisan tinker` → `DB::connection()->getPdo()`
- Verify environment: `php artisan about`

### Frontend Issues
- Check browser console (F12)
- Verify API URL in `.env`
- Check network tab for failed requests
- Verify token in localStorage
- Clear localStorage if authentication issues

### Test Failures
- Ensure test database is clean: Tests use in-memory SQLite
- Check for timezone issues (should use UTC in tests)
- Verify seeders are not interfering
- Run single test: `php artisan test --filter=testMethodName`

## Performance Optimization

### Backend
- Use eager loading for relationships
- Add database indexes for frequently queried columns
- Use chunking for large datasets: `Attendance::chunk(100, ...)`
- Cache expensive queries: `Cache::remember('key', 3600, ...)`

### Frontend
- Use `React.memo()` for expensive components
- Lazy load routes: `const Dashboard = lazy(() => import('./Dashboard'))`
- Debounce search inputs
- Optimize re-renders with `useCallback` and `useMemo`

## Additional Resources

- Laravel Documentation: https://laravel.com/docs/12.x
- React Documentation: https://react.dev
- TailwindCSS: https://tailwindcss.com
- PostgreSQL Documentation: https://www.postgresql.org/docs/
