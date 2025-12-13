# PROJECT STRUCTURE

## Overview
This is a WFH (Work From Home) Employee Attendance & Task Tracking System with separated frontend (React) and backend (Laravel 12) architecture for scalability.

**Current Version**: 1.2.0 (Advanced Security & Admin Features)
**Status**: Production Ready with Enhanced Security

## Technology Stack

### Backend
- **Framework**: Laravel 12
- **Database**: PostgreSQL
- **Authentication**: Laravel Sanctum (SPA Authentication)
- **API**: RESTful API
- **PHP Version**: 8.2+

### Frontend
- **Framework**: React 19+
- **State Management**: React Context API
- **HTTP Client**: Axios
- **Routing**: React Router v7
- **UI Framework**: TailwindCSS (v3.4.17)
- **Icons**: Lucide React
- **Notifications**: React Hot Toast
- **Date/Time**: date-fns
- **Security**: Google reCAPTCHA v2
- **Build Tool**: Vite

## Project Structure

### Backend Structure (Laravel)
```
backend/
├── app/
│   ├── Console/
│   │   └── Commands/
│   ├── Http/
│   │   ├── Controllers/
│   │   │   ├── Api/
│   │   │   │   ├── Auth/
│   │   │   │   │   ├── LoginController.php
│   │   │   │   │   ├── LogoutController.php
│   │   │   │   │   └── RegisterController.php    # Manager registration with team creation
│   │   │   │   ├── AttendanceController.php     # Check-in/Check-out endpoints
│   │   │   │   ├── TaskController.php           # Task CRUD operations
│   │   │   │   ├── EmployeeReportController.php # Employee's own reports
│   │   │   │   ├── ManagerReportController.php  # Manager's dashboard reports
│   │   │   │   ├── UserManagementController.php # User CRUD (Manager only)
│   │   │   │   ├── HolidayController.php        # Holiday management
│   │   │   │   ├── LeaveController.php          # Leave/Cuti management
│   │   │   │   ├── ActivityLogController.php    # View activity logs
│   │   │   │   ├── TeamManagementController.php # Super admin team management
│   │   │   │   ├── ChangePasswordController.php # Password change functionality
│   │   │   │   ├── ImpersonateController.php     # Super admin impersonation
│   │   │   │   ├── ManagerLeaveController.php   # Manager leave approval
│   │   │   │   ├── ManagerTaskController.php    # Manager task management
│   │   │   │   ├── ManagerAttendanceController.php # Manager attendance editing
│   │   │   │   └── TeamSettingsController.php   # Team settings management
│   │   ├── Middleware/
│   │   │   ├── CheckRole.php                    # Role-based access control
│   │   │   ├── LogUserActivity.php              # Middleware to log all activities
│   │   │   ├── EnsureTeamAccess.php             # Team-based data isolation
│   │   │   ├── EnsureSuperAdmin.php              # Super admin access control
│   │   │   └── CheckRegistrationEnabled.php     # Registration control middleware
│   │   ├── Requests/
│   │   │   ├── CheckInRequest.php
│   │   │   ├── CheckOutRequest.php
│   │   │   ├── TaskRequest.php
│   │   │   ├── UserRequest.php
│   │   │   └── RegisterRequest.php              # Registration validation with reCAPTCHA
│   │   └── Resources/
│   │       ├── AttendanceResource.php
│   │       ├── TaskResource.php
│   │       ├── UserResource.php
│   │       └── ActivityLogResource.php
│   ├── Models/
│   │   ├── User.php                             # id, name, email, password, role (enum: super_admin, manager, employee)
│   │   ├── Team.php                             # Team management with settings
│   │   ├── Attendance.php                       # id, user_id, check_in, check_out, date, total_hours
│   │   ├── Task.php                             # id, attendance_id, title, is_completed, blocker_reason
│   │   ├── Holiday.php                          # id, date, name, description
│   │   ├── Leave.php                            # id, user_id, start_date, end_date, reason, status
│   │   └── ActivityLog.php                      # id, user_id, action, description, ip_address, user_agent
│   ├── Services/
│   │   ├── AttendanceService.php                # Business logic for attendance
│   │   ├── TaskService.php                      # Business logic for tasks
│   │   ├── ReportService.php                    # Generate reports and statistics
│   │   ├── ActivityLogService.php               # Log user activities
│   │   └── RecaptchaService.php                 # Google reCAPTCHA verification
│   ├── Repositories/
│   │   ├── AttendanceRepository.php
│   │   ├── TaskRepository.php
│   │   ├── UserRepository.php
│   │   └── ActivityLogRepository.php
│   └── Enums/
│       ├── UserRole.php                         # Enum: SUPER_ADMIN, MANAGER, EMPLOYEE
│       ├── LeaveStatus.php                      # Enum: PENDING, APPROVED, REJECTED
│       └── ActivityType.php                     # Enum: CHECK_IN, CHECK_OUT, TASK_CREATED, etc.
├── database/
│   ├── migrations/
│   │   ├── 0001_01_01_000000_create_users_table.php
│   │   ├── 0001_01_01_000001_create_cache_table.php
│   │   ├── 0001_01_01_000002_create_jobs_table.php
│   │   ├── 0001_01_01_000003_create_sessions_table.php
│   │   ├── 2024_01_01_000004_create_personal_access_tokens_table.php
│   │   ├── 2025_11_01_182859_create_attendances_table.php
│   │   ├── 2025_11_01_182864_create_personal_access_tokens_table.php
│   │   ├── 2025_11_01_182869_create_sessions_table.php
│   │   ├── 2025_11_01_182874_create_cache_table.php
│   │   ├── 2025_11_01_182879_create_jobs_table.php
│   │   ├── 2025_11_01_182884_create_teams_table.php
│   │   ├── 2025_11_01_182889_create_holidays_table.php
│   │   ├── 2025_11_01_182894_create_leaves_table.php
│   │   ├── 2025_11_01_182899_create_tasks_table.php
│   │   ├── 2025_11_01_182904_create_activity_logs_table.php
│   │   ├── 2025_11_05_010950_add_leave_quota_to_users_table.php
│   │   ├── 2025_11_05_090813_convert_utc_timestamps_to_wib.php
│   │   ├── 2025_11_08_233818_add_team_id_to_holidays_and_activity_logs_tables.php
│   ├── seeders/
│   │   ├── UserSeeder.php                       # Seed default manager and employees
│   │   └── HolidaySeeder.php                    # Seed common holidays
│   └── factories/
│       ├── UserFactory.php
│       └── AttendanceFactory.php
├── routes/
│   └── api.php                                  # All API routes with versioning (v1)
└── tests/
    ├── Feature/
    │   ├── AttendanceTest.php
    │   ├── TaskTest.php
    │   └── AuthTest.php
    └── Unit/
        ├── AttendanceServiceTest.php
        └── ReportServiceTest.php
```

### Frontend Structure (React)
```
frontend/
├── public/
├── src/
│   ├── api/
│   │   ├── axios.js                             # Axios instance with interceptors
│   │   ├── auth.api.js                          # Authentication API calls (login, register, logout)
│   │   ├── attendance.api.js                    # Attendance API calls
│   │   ├── task.api.js                          # Task API calls
│   │   ├── report.api.js                        # Report API calls
│   │   ├── user.api.js                          # User management API calls
│   │   ├── holiday.api.js                       # Holiday API calls
│   │   ├── leave.api.js                         # Leave API calls
│   │   ├── activityLog.api.js                   # Activity log API calls
│   │   ├── impersonate.api.js                   # Super admin impersonation API
│   │   ├── teamManagement.api.js                # Team management API calls
│   │   └── changePassword.api.js                # Password change API
│   ├── components/
│   │   ├── common/
│   │   │   ├── Button.jsx
│   │   │   ├── Input.jsx
│   │   │   ├── Modal.jsx
│   │   │   ├── Table.jsx
│   │   │   ├── Loading.jsx
│   │   │   ├── PrivateRoute.jsx                 # Route guard for authentication
│   │   │   └── ProtectedRegisterRoute.jsx       # Route guard for registration
│   │   ├── layout/
│   │   │   ├── Navbar.jsx
│   │   │   ├── Sidebar.jsx
│   │   │   └── MainLayout.jsx
│   │   ├── attendance/
│   │   │   ├── CheckInModal.jsx
│   │   │   ├── CheckOutModal.jsx
│   │   │   └── AttendanceCard.jsx
│   │   ├── task/
│   │   │   ├── TaskList.jsx
│   │   │   ├── TaskItem.jsx
│   │   │   └── TaskForm.jsx
│   │   ├── report/
│   │   │   ├── EmployeeReport.jsx
│   │   │   ├── ManagerDashboard.jsx
│   │   │   ├── AttendanceChart.jsx
│   │   │   └── WorkHoursChart.jsx
│   │   └── admin/
│   │       ├── UserManagement.jsx
│   │       ├── HolidayManagement.jsx
│   │       ├── LeaveManagement.jsx
│   │       ├── ActivityLogViewer.jsx
│   │       ├── TeamSettings.jsx                # Team configuration
│   │       ├── ChangePassword.jsx              # Password change form
│   │       └── ImpersonationIndicator.jsx      # Shows when impersonating user
│   ├── contexts/
│   │   └── AuthContext.jsx                      # Authentication context
│   ├── hooks/
│   │   ├── useAuth.js
│   │   ├── useAttendance.js
│   │   ├── useReport.js
│   │   └── useRegistrationStatus.js           # Check registration availability
│   ├── pages/
│   │   ├── auth/
│   │   │   ├── Login.jsx                        # User login with reCAPTCHA
│   │   │   └── Register.jsx                     # Manager registration with team creation
│   │   ├── employee/
│   │   │   ├── Dashboard.jsx                    # Check-in/out, today's tasks
│   │   │   ├── MyReport.jsx                     # Personal work history
│   │   │   ├── MyLeave.jsx                      # Request leave
│   │   │   └── ChangePassword.jsx              # Employee password change
│   │   └── manager/
│   │       ├── Dashboard.jsx                    # Overview all employees
│   │       ├── EmployeeList.jsx                 # Manage employees
│   │       ├── Reports.jsx                      # Detailed reports
│   │       ├── HolidaySettings.jsx              # Holiday management
│   │       ├── LeaveApproval.jsx                # Approve/reject leave
│   │       ├── ActivityLogs.jsx                 # View all activity logs
│   │       ├── UserManagement.jsx               # User CRUD operations
│   │       ├── AttendanceManagement.jsx         # Edit/delete attendance records
│   │       ├── DailyAttendanceReport.jsx        # Daily attendance reports
│   │       ├── MonthlyAttendanceReport.jsx      # Monthly attendance reports
│   │       └── TeamSettings.jsx                # Team configuration
│   ├── utils/
│   │   ├── dateHelpers.js                       # Date formatting and calculations
│   │   ├── validators.js                        # Form validation helpers
│   │   └── constants.js                         # App constants (roles, status, etc.)
│   ├── pages/
│   │   └── super-admin/                         # Super admin specific pages
│   │       ├── TeamManagement.jsx               # Team CRUD operations
│   │       └── UserManagement.jsx               # Cross-team user management
│   ├── App.jsx
│   ├── main.jsx
│   └── routes.jsx                               # All application routes
├── .env.example
├── package.json
└── vite.config.js
```

## Database Schema Overview

### Tables and Relationships

1. **users**
   - Primary table for authentication
   - Fields: id, name, email, password, role, team_id, leave_quota_days, remaining_leave_days, is_active, impersonated_by, created_at, updated_at
   - Relationships: belongsTo Team, hasMany Attendances, hasMany Leaves, hasMany ActivityLogs

2. **teams**
   - Team management with customizable settings
   - Fields: id, name, slug, description, required_work_hours, default_leave_quota_days, max_leave_days_per_month, is_active, created_at, updated_at
   - Relationships: hasMany Users, hasMany Attendances, hasMany Holidays, hasMany ActivityLogs

3. **attendances**
   - Stores check-in and check-out records
   - Fields: id, user_id, team_id, check_in, check_out, date, total_hours, is_complete, is_overtime, edited_by, edit_reason, created_at, updated_at
   - Relationships: belongsTo User, belongsTo Team, hasMany Tasks

4. **tasks**
   - Stores tasks for each attendance session
   - Fields: id, attendance_id, title, is_completed, blocker_reason, created_at, updated_at
   - Relationship: belongsTo Attendance

5. **holidays**
   - Stores company holidays
   - Fields: id, team_id, date, name, description, created_at, updated_at
   - Relationship: belongsTo Team

6. **leaves**
   - Stores employee leave requests
   - Fields: id, user_id, start_date, end_date, reason, status, approved_by, approved_at, notes, created_at, updated_at
   - Relationship: belongsTo User

7. **activity_logs**
   - Stores all user activities for audit trail
   - Fields: id, user_id, team_id, action, description, ip_address, user_agent, impersonated_by, created_at
   - Relationship: belongsTo User, belongsTo Team

## Key Design Patterns

### Backend Patterns
- **Repository Pattern**: Separate data access logic from business logic
- **Service Layer**: Contains business logic and orchestrates repository calls
- **Resource Pattern**: Transform models into JSON responses
- **Middleware Pattern**: Handle cross-cutting concerns (auth, logging, CORS)

### Frontend Patterns
- **Component Composition**: Reusable UI components
- **Custom Hooks**: Encapsulate reusable logic
- **Context API**: Global state management for authentication
- **API Service Layer**: Centralized API calls

## API Versioning
All API endpoints are versioned using URL path:
- Base URL: `http://localhost:8000/api/v1`
- Example: `http://localhost:8000/api/v1/attendance/check-in`

## Authentication Flow
1. User logs in → Backend validates credentials
2. Backend generates Sanctum token
3. Frontend stores token in localStorage
4. All subsequent requests include token in Authorization header
5. Backend validates token on each request
6. Frontend redirects to login if token is invalid/expired

## Cross-Day Work Sessions
- System supports work sessions that span across midnight
- Employees can check-in at night (e.g., 23:00) and checkout the next day
- `findActiveByUser()` searches both today and yesterday for active sessions
- No automatic checkout - employees must manually checkout
- Provides flexibility for night shift workers and various work patterns

## Environment Variables

### Backend (.env)
```
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=wfh_attendance
DB_USERNAME=postgres
DB_PASSWORD=password

SANCTUM_STATEFUL_DOMAINS=localhost:5173
SESSION_DOMAIN=localhost
FRONTEND_URL=http://localhost:5173
```

### Frontend (.env)
```
VITE_API_URL=http://localhost:8000/api/v1
VITE_APP_NAME="WFH Attendance System"
VITE_RECAPTCHA_SITE_KEY=your_recaptcha_site_key_here
VITE_ENABLE_REGISTRATION=true
```

## Security Features

### Authentication & Authorization
- **Laravel Sanctum**: Token-based authentication for SPA
- **Role-based Access Control**: Super Admin, Manager, Employee roles
- **Team-based Data Isolation**: Users can only access their team's data
- **Google reCAPTCHA v2**: Protects against automated bots
- **Session Management**: Secure token storage and invalidation
- **Activity Logging**: Comprehensive audit trail

### User Management
- **Registration Control**: Can be enabled/disabled via environment variables
- **Password Security**: Hashed passwords with secure storage
- **Account Status**: Soft delete with audit trail
- **Impersonation**: Super admin can impersonate users for support

### Data Protection
- **Input Validation**: Comprehensive validation on all inputs
- **SQL Injection Prevention**: Using Laravel's ORM and query builder
- **CSRF Protection**: Built-in Laravel CSRF tokens
- **XSS Prevention**: Output escaping and sanitization

## Deployment Considerations
- Backend: Deploy to VPS with PHP 8.2+, PostgreSQL, Nginx
- Frontend: Build with `npm run build`, deploy to Netlify/Vercel or same VPS
- Use environment-specific .env files
- Enable CORS properly in Laravel for production domain
- Set up SSL certificates for both domains
- Configure Laravel Scheduler cron job for scheduled tasks (e.g., WhatsApp recap)
- Configure Google reCAPTCHA keys for production
- Set up proper logging and monitoring
- Database backup and recovery procedures
