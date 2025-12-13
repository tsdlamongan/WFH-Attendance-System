# FEATURES & USER STORIES

## Overview
This document describes all features, user stories, business logic, API endpoints, and validation rules for the WFH Attendance & Task Tracking System.

---

## Table of Contents
1. [Authentication & Authorization](#1-authentication--authorization)
2. [Employee Features](#2-employee-features)
3. [Manager Features](#3-manager-features)
4. [Super Admin Features](#4-super-admin-features)
5. [System Features](#5-system-features)
6. [API Endpoints Reference](#6-api-endpoints-reference)

---

## 1. Authentication & Authorization

### Feature 1.1: User Login

**User Story:**
> As a user (employee or manager), I want to log in with my email and password so that I can access my dashboard.

**Business Logic:**
- System validates email format and password
- System checks if user exists and credentials match
- System generates Sanctum token for authenticated session
- System returns user data and role
- Token stored in frontend localStorage
- User redirected to appropriate dashboard based on role

**Validation Rules:**
- Email: required, valid email format, exists in database
- Password: required, minimum 8 characters
- reCAPTCHA: required (Google reCAPTCHA v2 checkbox)

**API Endpoint:**
```
POST /api/v1/auth/login
```

**Request Body:**
```json
{
    "email": "employee@example.com",
    "password": "password123",
    "captcha_token": "03AOLTBLR..."
}
```

**Response (Success - 200):**
```json
{
    "success": true,
    "data": {
        "user": {
            "id": 1,
            "name": "John Doe",
            "email": "employee@example.com",
            "role": "employee"
        },
        "token": "1|xyz123abc456..."
    },
    "message": "Login successful"
}
```

**Response (Error - 401):**
```json
{
    "success": false,
    "message": "Invalid credentials"
}
```

---

### Feature 1.2: User Registration (Manager)

**User Story:**
> As a new manager, I want to register my account and create my team so that I can start managing employee attendance.

**Business Logic:**
- Registration can be enabled/disabled via environment variable
- Manager creates personal account and team simultaneously
- System generates team with default settings
- Manager assigned as team owner
- Auto-login after successful registration

**Validation Rules:**
- Name: required, string, maximum 255 characters
- Email: required, valid email, unique in database
- Password: required, minimum 8 characters, confirmed
- Team name: required, string, maximum 255 characters
- Team description: optional, string, maximum 1000 characters
- Required work hours: optional, numeric, 1-24 (default: 7)
- Default leave quota: optional, integer, 0-365 (default: 12)
- Max leave per month: optional, integer, 0-31 (default: 5)
- reCAPTCHA: required (Google reCAPTCHA v2 checkbox)

**API Endpoint:**
```
POST /api/v1/auth/register
```

**Request Body:**
```json
{
    "name": "John Manager",
    "email": "manager@company.com",
    "password": "SecurePass123",
    "password_confirmation": "SecurePass123",
    "team_name": "Tech Team",
    "team_description": "Development team for project X",
    "required_work_hours": "8",
    "default_leave_quota_days": "15",
    "max_leave_days_per_month": "6",
    "captcha_token": "03AOLTBLR..."
}
```

**Response (Success - 201):**
```json
{
    "success": true,
    "data": {
        "user": {
            "id": 1,
            "name": "John Manager",
            "email": "manager@company.com",
            "role": "manager"
        },
        "token": "1|xyz123abc456...",
        "team": {
            "id": 1,
            "name": "Tech Team",
            "slug": "tech-team",
            "required_work_hours": 8,
            "default_leave_quota_days": 15,
            "max_leave_days_per_month": 6
        }
    },
    "message": "Registration successful! Team created."
}
```

**Response (Error - 403):**
```json
{
    "success": false,
    "message": "Registration is currently disabled",
    "errors": {
        "registration": ["Pendaftaran akun baru sedang dinonaktifkan"]
    }
}
```

---

### Feature 1.3: Registration Status Check

**User Story:**
> As a frontend application, I want to check if registration is enabled so I can show/hide registration options.

**API Endpoint:**
```
GET /api/v1/auth/registration-status
```

**Response (Success - 200):**
```json
{
    "enabled": true,
    "message": "Registration is enabled"
}
```

---

### Feature 1.4: User Logout

**User Story:**
> As a logged-in user, I want to log out so that my session ends securely.

**Business Logic:**
- System revokes current access token
- Frontend removes token from localStorage
- User redirected to login page

**API Endpoint:**
```
POST /api/v1/auth/logout
```

**Response (Success - 200):**
```json
{
    "success": true,
    "message": "Logged out successfully"
}
```

---

## 2. Employee Features

### Feature 2.1: Check-In with Tasks

**User Story:**
> As an employee, I want to check in at the start of my work session and provide a list of tasks I plan to work on.

**Business Logic:**
- Employee can only check in if they haven't already checked in today OR if they have checked out from a previous session today (installment system)
- System records current timestamp as check_in
- System creates attendance record with status "active"
- System creates task records linked to attendance
- System logs activity
- Check-in time can be any time of day (no restriction)

**UI/UX Enhancements (v1.1.0):**
- Keyboard shortcuts: Press `Enter` to add new task, `Ctrl+Enter` to submit
- Multi-line paste support: Copy-paste from notepad/excel, auto-split by lines
- Auto-focus to new task field after Enter
- Visual hints and tips displayed in modal
- Task counter showing current count (X/20)

**Validation Rules:**
- Tasks: required, array, minimum 1 task, maximum 20 tasks
- Task title: required, string, maximum 255 characters
- User must not have active (unchecked-out) attendance

**API Endpoint:**
```
POST /api/v1/attendance/check-in
```

**Request Body:**
```json
{
    "tasks": [
        {
            "title": "Implement user authentication module"
        },
        {
            "title": "Fix bug in report generation"
        },
        {
            "title": "Review pull requests"
        }
    ]
}
```

**Response (Success - 201):**
```json
{
    "success": true,
    "data": {
        "id": 123,
        "user_id": 1,
        "check_in": "2024-01-15T09:00:00.000000Z",
        "check_out": null,
        "date": "2024-01-15",
        "total_hours": 0,
        "tasks": [
            {
                "id": 1,
                "attendance_id": 123,
                "title": "Implement user authentication module",
                "is_completed": false,
                "blocker_reason": null
            },
            {
                "id": 2,
                "attendance_id": 123,
                "title": "Fix bug in report generation",
                "is_completed": false,
                "blocker_reason": null
            }
        ]
    },
    "message": "Checked in successfully"
}
```

**Response (Error - 422):**
```json
{
    "success": false,
    "message": "You have already checked in. Please check out first.",
    "errors": {
        "check_in": ["Active attendance session already exists"]
    }
}
```

---

### Feature 2.2: Check-Out with Task Status

**User Story:**
> As an employee, I want to check out at the end of my work session and mark which tasks I completed, including reasons for incomplete tasks.

**Business Logic:**
- Employee can only check out if they have active check-in
- System records current timestamp as check_out
- System calculates total_hours (difference between check_in and check_out in hours)
- System updates task statuses and blocker reasons
- System logs activity
- If total daily hours < 7, system flags as incomplete workday
- If total daily hours ≥ 7, system marks day as complete
- If total daily hours > 7, system records as overtime

**Validation Rules:**
- Tasks: required, array
- Task ID: required, exists in database, belongs to current attendance
- Is completed: required, boolean
- Blocker reason: required if is_completed = false, string, maximum 500 characters
- Must have active check-in session

**API Endpoint:**
```
POST /api/v1/attendance/check-out
```

**Request Body:**
```json
{
    "attendance_id": 123,
    "tasks": [
        {
            "id": 1,
            "is_completed": true,
            "blocker_reason": null
        },
        {
            "id": 2,
            "is_completed": false,
            "blocker_reason": "Waiting for API credentials from third-party vendor"
        },
        {
            "id": 3,
            "is_completed": true,
            "blocker_reason": null
        }
    ]
}
```

**Response (Success - 200):**
```json
{
    "success": true,
    "data": {
        "id": 123,
        "user_id": 1,
        "check_in": "2024-01-15T09:00:00.000000Z",
        "check_out": "2024-01-15T16:30:00.000000Z",
        "date": "2024-01-15",
        "total_hours": 7.5,
        "is_complete": true,
        "is_overtime": true,
        "tasks": [
            {
                "id": 1,
                "title": "Implement user authentication module",
                "is_completed": true,
                "blocker_reason": null
            },
            {
                "id": 2,
                "title": "Fix bug in report generation",
                "is_completed": false,
                "blocker_reason": "Waiting for API credentials from third-party vendor"
            }
        ]
    },
    "message": "Checked out successfully. Total hours: 7.5"
}
```

---

### Feature 2.3: View Personal Work Report

**User Story:**
> As an employee, I want to view my daily, weekly, and monthly work reports so that I can track my productivity.

**Business Logic:**
- Display attendance records with check-in/out times
- Show total hours worked per day
- Calculate total hours per week/month
- Show task completion rate
- Display incomplete tasks with blockers
- Highlight days with < 7 hours (incomplete)
- Highlight days with > 7 hours (overtime)
- Filter by date range

**UI/UX Enhancements (v1.1.0):**
- Expandable task details: Click on "X completed / Y incomplete" to view task list
- Color-coded task cards: Green for completed, Red for incomplete
- Shows blocker reasons for incomplete tasks
- Chevron icons indicate expand/collapse state

**API Endpoint:**
```
GET /api/v1/reports/my-report?start_date=2024-01-01&end_date=2024-01-31
```

**Response (Success - 200):**
```json
{
    "success": true,
    "data": {
        "summary": {
            "total_days_worked": 22,
            "total_hours": 154.5,
            "average_hours_per_day": 7.02,
            "required_hours": 154,
            "overtime_hours": 0.5,
            "incomplete_days": 0,
            "task_completion_rate": 95.5
        },
        "attendances": [
            {
                "date": "2024-01-15",
                "sessions": [
                    {
                        "check_in": "2024-01-15T09:00:00.000000Z",
                        "check_out": "2024-01-15T16:30:00.000000Z",
                        "total_hours": 7.5,
                        "tasks_completed": 2,
                        "tasks_incomplete": 1
                    }
                ],
                "daily_total_hours": 7.5,
                "status": "complete"
            },
            {
                "date": "2024-01-16",
                "sessions": [
                    {
                        "check_in": "2024-01-16T08:00:00.000000Z",
                        "check_out": "2024-01-16T12:00:00.000000Z",
                        "total_hours": 4.0,
                        "tasks_completed": 1,
                        "tasks_incomplete": 0
                    },
                    {
                        "check_in": "2024-01-16T14:00:00.000000Z",
                        "check_out": "2024-01-16T17:00:00.000000Z",
                        "total_hours": 3.0,
                        "tasks_completed": 2,
                        "tasks_incomplete": 0
                    }
                ],
                "daily_total_hours": 7.0,
                "status": "complete"
            }
        ]
    }
}
```

---

### Feature 2.4: View Today's Status

**User Story:**
> As an employee, I want to see my current work status for today (whether I'm checked in, hours worked so far, and progress toward 7 hours).

**API Endpoint:**
```
GET /api/v1/attendance/today
```

**Response (Success - 200):**
```json
{
    "success": true,
    "data": {
        "date": "2024-01-15",
        "is_checked_in": true,
        "current_session": {
            "id": 123,
            "check_in": "2024-01-15T14:00:00.000000Z",
            "elapsed_hours": 2.5
        },
        "today_total_hours": 6.5,
        "required_hours": 7,
        "remaining_hours": 0.5,
        "previous_sessions": [
            {
                "check_in": "2024-01-15T09:00:00.000000Z",
                "check_out": "2024-01-15T13:00:00.000000Z",
                "total_hours": 4.0
            }
        ]
    }
}
```

---

### Feature 2.5: Request Leave

**User Story:**
> As an employee, I want to request leave/cuti for specific dates so that I can inform my manager about my absence.

**Business Logic:**
- Employee submits leave request with date range and reason
- System creates leave record with status "pending"
- Manager receives notification
- System prevents check-in during approved leave dates

**Validation Rules:**
- Start date: required, date, must be future date or today
- End date: required, date, must be >= start_date
- Reason: required, string, minimum 10 characters, maximum 500 characters

**API Endpoint:**
```
POST /api/v1/leaves
```

**Request Body:**
```json
{
    "start_date": "2024-02-01",
    "end_date": "2024-02-03",
    "reason": "Family emergency - need to travel to hometown"
}
```

**Response (Success - 201):**
```json
{
    "success": true,
    "data": {
        "id": 1,
        "user_id": 1,
        "start_date": "2024-02-01",
        "end_date": "2024-02-03",
        "reason": "Family emergency - need to travel to hometown",
        "status": "pending"
    },
    "message": "Leave request submitted successfully"
}
```

---

### Feature 2.6: Add Tasks to Existing Session

**User Story:**
> As an employee who is checked in, I want to add new tasks during my work session if I realize there are additional things I need to work on.

**Business Logic:**
- Employee can only add tasks if they have active check-in
- New tasks added to current attendance session
- System logs activity

**Validation Rules:**
- Tasks: required, array, minimum 1 task
- Task title: required, string, maximum 255 characters
- Must have active attendance session

**API Endpoint:**
```
POST /api/v1/tasks/add
```

**Request Body:**
```json
{
    "attendance_id": 123,
    "tasks": [
        {
            "title": "Emergency bug fix for production issue"
        }
    ]
}
```

**Response (Success - 201):**
```json
{
    "success": true,
    "data": {
        "tasks": [
            {
                "id": 4,
                "attendance_id": 123,
                "title": "Emergency bug fix for production issue",
                "is_completed": false,
                "blocker_reason": null
            }
        ]
    },
    "message": "Tasks added successfully"
}
```

---

## 3. Manager Features

### Feature 3.1: View All Employees Dashboard

**User Story:**
> As a manager, I want to see an overview of all employees' attendance status so that I can monitor team productivity.

**Business Logic:**
- Display list of all employees
- Show current status (checked in/out)
- Show today's hours for each employee
- Show weekly/monthly hours
- Highlight employees with incomplete work hours
- Show employees on leave
- Filter and search capabilities

**API Endpoint:**
```
GET /api/v1/manager/dashboard?date=2024-01-15
```

**Response (Success - 200):**
```json
{
    "success": true,
    "data": {
        "summary": {
            "total_employees": 10,
            "checked_in_now": 7,
            "on_leave": 1,
            "average_daily_hours": 7.2
        },
        "employees": [
            {
                "id": 1,
                "name": "John Doe",
                "email": "john@example.com",
                "status": "checked_in",
                "current_session": {
                    "check_in": "2024-01-15T09:00:00.000000Z",
                    "elapsed_hours": 3.5
                },
                "today_total_hours": 3.5,
                "week_total_hours": 28.0,
                "month_total_hours": 140.5
            },
            {
                "id": 2,
                "name": "Jane Smith",
                "email": "jane@example.com",
                "status": "checked_out",
                "current_session": null,
                "today_total_hours": 7.0,
                "week_total_hours": 35.0,
                "month_total_hours": 154.0
            },
            {
                "id": 3,
                "name": "Bob Wilson",
                "email": "bob@example.com",
                "status": "on_leave",
                "leave": {
                    "start_date": "2024-01-15",
                    "end_date": "2024-01-17",
                    "reason": "Medical leave"
                }
            }
        ]
    }
}
```

---

### Feature 3.2: View Detailed Employee Report

**User Story:**
> As a manager, I want to view detailed work reports for any employee so that I can evaluate their performance and productivity.

**API Endpoint:**
```
GET /api/v1/manager/reports/employee/{userId}?start_date=2024-01-01&end_date=2024-01-31
```

**Response:** (Similar to Employee's personal report but for any employee)

---

### Feature 3.3: Manage Users (CRUD)

**User Story:**
> As a manager, I want to create, update, and delete user accounts so that I can manage my team.

#### 3.3.1: Create User

**Validation Rules:**
- Name: required, string, maximum 255 characters
- Email: required, valid email, unique in database
- Password: required, minimum 8 characters, confirmed
- Role: required, enum (employee, manager)

**API Endpoint:**
```
POST /api/v1/manager/users
```

**Request Body:**
```json
{
    "name": "New Employee",
    "email": "newemployee@example.com",
    "password": "SecurePass123!",
    "password_confirmation": "SecurePass123!",
    "role": "employee"
}
```

**Response (Success - 201):**
```json
{
    "success": true,
    "data": {
        "id": 11,
        "name": "New Employee",
        "email": "newemployee@example.com",
        "role": "employee",
        "created_at": "2024-01-15T10:00:00.000000Z"
    },
    "message": "User created successfully"
}
```

#### 3.3.2: Update User

**API Endpoint:**
```
PUT /api/v1/manager/users/{id}
```

**Request Body:**
```json
{
    "name": "Updated Name",
    "email": "updated@example.com",
    "role": "manager"
}
```

#### 3.3.3: Delete User

**Business Logic:**
- Soft delete user (keep records for audit)
- Cannot delete user with active attendance
- All attendance history preserved

**API Endpoint:**
```
DELETE /api/v1/manager/users/{id}
```

**Response (Success - 200):**
```json
{
    "success": true,
    "message": "User deleted successfully"
}
```

---

### Feature 3.4: Edit/Delete Attendance Records

**User Story:**
> As a manager, I want to edit or delete attendance records to correct mistakes or handle special cases.

#### 3.4.1: Edit Attendance

**Business Logic:**
- Manager can modify check-in/check-out times
- System recalculates total_hours automatically
- System logs this action in activity log
- Reason required for audit trail

**Validation Rules:**
- Check-in: required, datetime
- Check-out: nullable, datetime, must be after check-in
- Reason: required, string, minimum 10 characters

**API Endpoint:**
```
PUT /api/v1/manager/attendances/{id}
```

**Request Body:**
```json
{
    "check_in": "2024-01-15T09:00:00",
    "check_out": "2024-01-15T17:00:00",
    "reason": "Employee forgot to check out, verified via chat"
}
```

**Response (Success - 200):**
```json
{
    "success": true,
    "data": {
        "id": 123,
        "user_id": 1,
        "check_in": "2024-01-15T09:00:00.000000Z",
        "check_out": "2024-01-15T17:00:00.000000Z",
        "total_hours": 8.0,
        "edited_by": 5,
        "edited_at": "2024-01-15T18:00:00.000000Z",
        "edit_reason": "Employee forgot to check out, verified via chat"
    },
    "message": "Attendance updated successfully"
}
```

#### 3.4.2: Delete Attendance

**Business Logic:**
- Soft delete only (mark as deleted)
- Requires reason for audit
- All related tasks also marked as deleted

**API Endpoint:**
```
DELETE /api/v1/manager/attendances/{id}
```

**Request Body:**
```json
{
    "reason": "Duplicate entry - employee checked in twice by mistake"
}
```

---

### Feature 3.5: Manage Holidays

**User Story:**
> As a manager, I want to set company holidays so that employees are not expected to work on those days.

**Business Logic:**
- Add/edit/delete holiday dates
- Holiday dates excluded from required work hours calculation
- Employees cannot check in on holidays (with warning message)

#### 3.5.1: Create Holiday

**Validation Rules:**
- Date: required, date, unique
- Name: required, string, maximum 255 characters
- Description: nullable, string, maximum 500 characters

**API Endpoint:**
```
POST /api/v1/manager/holidays
```

**Request Body:**
```json
{
    "date": "2024-12-25",
    "name": "Christmas Day",
    "description": "Company holiday - Christmas celebration"
}
```

#### 3.5.2: List Holidays

**API Endpoint:**
```
GET /api/v1/holidays?year=2024
```

**Response (Success - 200):**
```json
{
    "success": true,
    "data": [
        {
            "id": 1,
            "date": "2024-12-25",
            "name": "Christmas Day",
            "description": "Company holiday - Christmas celebration"
        },
        {
            "id": 2,
            "date": "2024-01-01",
            "name": "New Year's Day",
            "description": "Company holiday - New Year celebration"
        }
    ]
}
```

#### 3.5.3: Update Holiday

**API Endpoint:**
```
PUT /api/v1/manager/holidays/{id}
```

#### 3.5.4: Delete Holiday

**API Endpoint:**
```
DELETE /api/v1/manager/holidays/{id}
```

---

### Feature 3.6: Approve/Reject Leave Requests

**User Story:**
> As a manager, I want to review and approve or reject employee leave requests.

**Business Logic:**
- Manager sees all pending leave requests
- Can approve or reject with optional notes
- Employee receives notification of decision

#### 3.6.1: List Leave Requests

**API Endpoint:**
```
GET /api/v1/manager/leaves?status=pending
```

**Response (Success - 200):**
```json
{
    "success": true,
    "data": [
        {
            "id": 1,
            "user": {
                "id": 1,
                "name": "John Doe",
                "email": "john@example.com"
            },
            "start_date": "2024-02-01",
            "end_date": "2024-02-03",
            "reason": "Family emergency",
            "status": "pending",
            "requested_at": "2024-01-15T10:00:00.000000Z"
        }
    ]
}
```

#### 3.6.2: Approve Leave

**API Endpoint:**
```
PUT /api/v1/manager/leaves/{id}/approve
```

**Request Body:**
```json
{
    "notes": "Approved. Take care and get well soon."
}
```

**Response (Success - 200):**
```json
{
    "success": true,
    "data": {
        "id": 1,
        "status": "approved",
        "approved_by": 5,
        "approved_at": "2024-01-15T11:00:00.000000Z",
        "notes": "Approved. Take care and get well soon."
    },
    "message": "Leave request approved"
}
```

#### 3.6.3: Reject Leave

**API Endpoint:**
```
PUT /api/v1/manager/leaves/{id}/reject
```

**Request Body:**
```json
{
    "notes": "We have a critical deadline during this period. Can you reschedule?"
}
```

---

### Feature 3.7: View Activity Logs

**User Story:**
> As a manager, I want to view all user activities (logins, check-ins, edits, etc.) for audit and security purposes.

**Business Logic:**
- Records all important actions: login, logout, check-in, check-out, task updates, data edits
- Includes user info, timestamp, IP address, user agent
- Searchable and filterable by user, action type, date range

**API Endpoint:**
```
GET /api/v1/manager/activity-logs?user_id=1&action=check_in&start_date=2024-01-01
```

**Response (Success - 200):**
```json
{
    "success": true,
    "data": {
        "logs": [
            {
                "id": 1,
                "user": {
                    "id": 1,
                    "name": "John Doe"
                },
                "action": "check_in",
                "description": "User checked in with 3 tasks",
                "ip_address": "192.168.1.100",
                "user_agent": "Mozilla/5.0...",
                "created_at": "2024-01-15T09:00:00.000000Z"
            },
            {
                "id": 2,
                "user": {
                    "id": 1,
                    "name": "John Doe"
                },
                "action": "check_out",
                "description": "User checked out. Total hours: 7.5",
                "ip_address": "192.168.1.100",
                "user_agent": "Mozilla/5.0...",
                "created_at": "2024-01-15T16:30:00.000000Z"
            },
            {
                "id": 3,
                "user": {
                    "id": 5,
                    "name": "Manager Admin"
                },
                "action": "attendance_edited",
                "description": "Edited attendance #123 for John Doe. Reason: Employee forgot to check out",
                "ip_address": "192.168.1.50",
                "user_agent": "Mozilla/5.0...",
                "created_at": "2024-01-15T18:00:00.000000Z"
            }
        ],
        "pagination": {
            "current_page": 1,
            "total_pages": 10,
            "per_page": 50,
            "total": 500
        }
    }
}
```

---

## 4. Super Admin Features

### Feature 4.1: Team Management

**User Story:**
> As a super admin, I want to manage all teams in the system so I can oversee multiple organizations.

#### 4.1.1: List All Teams
**API Endpoint:**
```
GET /api/v1/super-admin/teams
```

**Response (Success - 200):**
```json
{
    "success": true,
    "data": [
        {
            "id": 1,
            "name": "Tech Team",
            "slug": "tech-team",
            "description": "Development team",
            "required_work_hours": 7,
            "default_leave_quota_days": 12,
            "max_leave_days_per_month": 5,
            "is_active": true,
            "users_count": 15,
            "created_at": "2024-01-01T00:00:00.000000Z"
        }
    ]
}
```

#### 4.1.2: Create Team
**API Endpoint:**
```
POST /api/v1/super-admin/teams
```

#### 4.1.3: Update Team
**API Endpoint:**
```
PUT /api/v1/super-admin/teams/{id}
```

#### 4.1.4: Delete Team
**Business Logic:**
- Cannot delete teams with users
- Soft delete for audit trail

**API Endpoint:**
```
DELETE /api/v1/super-admin/teams/{id}
```

---

### Feature 4.2: Cross-Team User Management

**User Story:**
> As a super admin, I want to view and update users from any team so I can manage the entire system.

**API Endpoint:**
```
GET /api/v1/super-admin/users
```

**API Endpoint:**
```
PUT /api/v1/super-admin/users/{id}
```

---

### Feature 4.3: User Impersonation

**User Story:**
> As a super admin, I want to impersonate other users so I can troubleshoot issues and provide support.

**Business Logic:**
- Super admin can impersonate any user except other super admins
- Original super admin session preserved
- Can stop impersonation at any time
- All actions logged as impersonated

**API Endpoint:**
```
POST /api/v1/super-admin/impersonate/{userId}
```

**Response (Success - 200):**
```json
{
    "success": true,
    "data": {
        "impersonated_user": {
            "id": 5,
            "name": "John Employee",
            "email": "john@example.com",
            "role": "employee",
            "team_id": 1
        },
        "impersonator_token": "2|impersonate_token_xyz...",
        "original_user": {
            "id": 1,
            "name": "Super Admin",
            "role": "super_admin"
        }
    },
    "message": "Impersonation started"
}
```

**API Endpoint:**
```
POST /api/v1/super-admin/stop-impersonate
```

**Response (Success - 200):**
```json
{
    "success": true,
    "message": "Impersonation stopped",
    "data": {
        "original_user": {
            "id": 1,
            "name": "Super Admin",
            "role": "super_admin"
        }
    }
}
```

---

### Feature 4.4: Super Admin Access

**Business Logic:**
- Super admin can bypass team access restrictions
- Can access any team's data
- Can perform manager operations on any team
- Cannot perform regular employee operations (check-in/out)

**Permissions:**
- View all teams and users
- Manage team settings
- Create/delete teams (when empty)
- Impersonate any user (except super admin)
- Access all managerial endpoints across teams

---

## 5. System Features

### Feature 5.1: Cross-Day Work Sessions

**Business Logic:**
- System supports work sessions that span across midnight
- Employees can check-in at night (e.g., 22:00 or 23:00) and continue working past midnight
- Employees must manually checkout - no automatic checkout
- `findActiveByUser()` searches both today and yesterday for active sessions
- Provides flexibility for night shift workers and various work patterns

**Use Cases:**
- Night shift workers who start at 22:00 and work until 5:00 AM next day
- Employees working late to meet deadlines
- Flexible work schedules across different time zones

**Implementation Details:**
```php
// AttendanceRepository::findActiveByUser()
// Searches today and yesterday for unchecked-out sessions
return Attendance::where('user_id', $user->id)
    ->whereNotNull('check_in')
    ->whereNull('check_out')
    ->where(function ($query) {
        $query->whereDate('date', Carbon::today())
              ->orWhereDate('date', Carbon::yesterday());
    })
    ->with('tasks')
    ->orderBy('date', 'desc')
    ->orderBy('check_in', 'desc')
    ->first();
```

**Important Notes:**
- Employees are responsible for checking out manually
- Managers can edit attendance records if employees forget to checkout
- Activity logs track all manual edits for audit purposes

---

### Feature 5.2: Work Hours Calculation

**Business Logic:**
- Required hours per day: **7 hours**
- Total hours = Sum of all session hours for the day
- Installment system: Employee can work multiple sessions per day
- Example: 
  - Session 1: 9:00 AM - 12:00 PM = 3 hours
  - Session 2: 2:00 PM - 6:00 PM = 4 hours
  - **Total: 7 hours (complete)**

**Status Definitions:**
- **Complete**: Total daily hours ≥ 7.0
- **Incomplete**: Total daily hours < 7.0
- **Overtime**: Total daily hours > 7.0 (excess hours tracked separately)

**Calculation Example:**
```php
// Session 1: 4 hours
// Session 2: 3.5 hours
// Total: 7.5 hours
// Status: Complete + 0.5 overtime
```

---

### Feature 5.3: Dashboard Statistics

**Business Logic:**
Calculate and display various statistics for both employees and managers.

#### Employee Dashboard Stats:
- Today's hours worked
- This week's total hours
- This month's total hours
- Task completion rate
- Days with incomplete hours this month

#### Manager Dashboard Stats:
- Total employees
- Employees currently checked in
- Average daily hours (team)
- Total overtime hours (team)
- Employees with most incomplete days
- Team task completion rate

---

### Feature 5.4: Google reCAPTCHA Integration

**Business Logic:**
- All authentication forms protected by Google reCAPTCHA v2
- Prevents automated bots and spam registrations
- Configurable via environment variables
- Bypassed in testing environment

**Configuration:**
- Frontend: `VITE_RECAPTCHA_SITE_KEY`
- Backend: `RECAPTCHA_SECRET_KEY`
- reCAPTCHA type: v2 "I'm not a robot" checkbox

**Validation:**
- reCAPTCHA token required for login and register
- Token verified against Google API
- Failed verification blocks form submission

---

### Feature 5.5: Registration Control

**Business Logic:**
- System administrators can enable/disable public registration
- Multi-layer protection: frontend + backend
- Configurable via environment variables
- Graceful UI feedback when disabled

**Environment Variables:**
- Backend: `ENABLE_REGISTRATION` (true/false)
- Frontend: `VITE_ENABLE_REGISTRATION` (true/false)

**Use Cases:**
- Production: Disable public registration
- Development: Enable for testing
- Private beta: API enabled, frontend disabled

---

## 6. API Endpoints Reference

### Authentication Endpoints
| Method | Endpoint | Description | Auth Required |
|--------|----------|-------------|---------------|
| POST | `/api/v1/auth/login` | User login | No |
| POST | `/api/v1/auth/register` | User registration (manager) | No |
| POST | `/api/v1/auth/logout` | User logout | Yes |
| GET | `/api/v1/auth/registration-status` | Check registration status | No |

### Attendance Endpoints (Employee)
| Method | Endpoint | Description | Auth Required | Role |
|--------|----------|-------------|---------------|------|
| POST | `/api/v1/attendance/check-in` | Check in with tasks | Yes | Employee |
| POST | `/api/v1/attendance/check-out` | Check out with task status | Yes | Employee |
| GET | `/api/v1/attendance/today` | Get today's status | Yes | Employee |
| GET | `/api/v1/attendances/my-history` | Get personal attendance history | Yes | Employee |

### Task Endpoints (Employee)
| Method | Endpoint | Description | Auth Required | Role |
|--------|----------|-------------|---------------|------|
| POST | `/api/v1/tasks/add` | Add tasks to current session | Yes | Employee |
| GET | `/api/v1/tasks/incomplete` | Get all incomplete tasks | Yes | Employee |

### Report Endpoints (Employee)
| Method | Endpoint | Description | Auth Required | Role |
|--------|----------|-------------|---------------|------|
| GET | `/api/v1/reports/my-report` | Get personal work report | Yes | Employee |

### Leave Endpoints (Employee)
| Method | Endpoint | Description | Auth Required | Role |
|--------|----------|-------------|---------------|------|
| POST | `/api/v1/leaves` | Request leave | Yes | Employee |
| GET | `/api/v1/leaves/my-requests` | Get own leave requests | Yes | Employee |

### Manager Endpoints
| Method | Endpoint | Description | Auth Required | Role |
|--------|----------|-------------|---------------|------|
| GET | `/api/v1/manager/dashboard` | Manager dashboard overview | Yes | Manager |
| GET | `/api/v1/manager/reports/employee/{id}` | Get employee report | Yes | Manager |
| GET | `/api/v1/manager/attendances` | Get all attendances | Yes | Manager |
| PUT | `/api/v1/manager/attendances/{id}` | Edit attendance | Yes | Manager |
| DELETE | `/api/v1/manager/attendances/{id}` | Delete attendance | Yes | Manager |

### User Management Endpoints (Manager)
| Method | Endpoint | Description | Auth Required | Role |
|--------|----------|-------------|---------------|------|
| GET | `/api/v1/manager/users` | List all users | Yes | Manager |
| POST | `/api/v1/manager/users` | Create user | Yes | Manager |
| PUT | `/api/v1/manager/users/{id}` | Update user | Yes | Manager |
| DELETE | `/api/v1/manager/users/{id}` | Delete user | Yes | Manager |

### Holiday Management Endpoints (Manager)
| Method | Endpoint | Description | Auth Required | Role |
|--------|----------|-------------|---------------|------|
| GET | `/api/v1/holidays` | List holidays | Yes | Both |
| POST | `/api/v1/manager/holidays` | Create holiday | Yes | Manager |
| PUT | `/api/v1/manager/holidays/{id}` | Update holiday | Yes | Manager |
| DELETE | `/api/v1/manager/holidays/{id}` | Delete holiday | Yes | Manager |

### Leave Management Endpoints (Manager)
| Method | Endpoint | Description | Auth Required | Role |
|--------|----------|-------------|---------------|------|
| GET | `/api/v1/manager/leaves` | List all leave requests | Yes | Manager |
| PUT | `/api/v1/manager/leaves/{id}/approve` | Approve leave | Yes | Manager |
| PUT | `/api/v1/manager/leaves/{id}/reject` | Reject leave | Yes | Manager |

### Activity Log Endpoints (Manager)
| Method | Endpoint | Description | Auth Required | Role |
|--------|----------|-------------|---------------|------|
| GET | `/api/v1/manager/activity-logs` | View activity logs | Yes | Manager |

### Super Admin Endpoints
| Method | Endpoint | Description | Auth Required | Role |
|--------|----------|-------------|---------------|------|
| GET | `/api/v1/super-admin/teams` | List all teams | Yes | Super Admin |
| POST | `/api/v1/super-admin/teams` | Create team | Yes | Super Admin |
| GET | `/api/v1/super-admin/teams/{id}` | Get team details | Yes | Super Admin |
| PUT | `/api/v1/super-admin/teams/{id}` | Update team | Yes | Super Admin |
| DELETE | `/api/v1/super-admin/teams/{id}` | Delete team | Yes | Super Admin |
| GET | `/api/v1/super-admin/users` | List users across teams | Yes | Super Admin |
| PUT | `/api/v1/super-admin/users/{id}` | Update user across teams | Yes | Super Admin |
| POST | `/api/v1/super-admin/impersonate/{userId}` | Start impersonation | Yes | Super Admin |
| POST | `/api/v1/super-admin/stop-impersonate` | Stop impersonation | Yes | Impersonated User |

---

## 7. Business Rules Summary

### Work Hours Rules
1. **Required daily hours**: 7 hours
2. **Installment allowed**: Yes (multiple check-in/out per day)
3. **Overtime tracking**: Yes (hours > 7 recorded)
4. **No buffer/tolerance**: Exactly 7 hours required
5. **Manual checkout**: Employees must checkout manually
6. **Cross-day sessions**: Supported (can check-in at night and checkout next day)

### Task Management Rules
1. **Minimum tasks per check-in**: 1 task
2. **Maximum tasks per check-in**: 20 tasks
3. **Tasks can be added**: During active session
4. **Blocker reason required**: When task incomplete at checkout
5. **Task cannot be deleted**: Only marked complete/incomplete

### Leave Rules
1. **Leave request**: Must be submitted before leave date
2. **Manager approval**: Required for all leaves
3. **Check-in during leave**: Blocked by system
4. **Leave dates**: Don't count toward required work hours

### Holiday Rules
1. **Set by manager**: Only managers can add/edit holidays
2. **Check-in blocked**: System prevents check-in on holidays
3. **Doesn't count toward**: Required work hours

### Authorization Rules
1. **Employee can**:
   - Check-in/out for themselves only
   - View their own reports
   - Request leave
   - Add tasks to their sessions

2. **Manager can**:
   - View all employee data
   - Edit/delete any attendance
   - Manage users (CRUD)
   - Manage holidays
   - Approve/reject leaves
   - View activity logs
   - Cannot check-in/out (unless also employee role)

---

## 8. Edge Cases & Special Scenarios

### Scenario 1: Employee forgets to check out
- **Solution**: Manager can manually edit attendance record
- **Activity logged**: Yes (including manager's edit reason)
- **Employee notification**: Recommended to inform employee of the edit

### Scenario 2: Employee works less than 7 hours
- **Status**: Marked as "incomplete"
- **Manager notification**: Optional
- **Carried over**: No (each day independent)

### Scenario 3: Employee works on holiday
- **Prevented**: System blocks check-in with message
- **Override**: Manager can manually add attendance if needed

### Scenario 4: Multiple check-ins same day
- **Allowed**: Yes (installment system)
- **New tasks**: Can choose new or continue from previous session
- **Total calculation**: Sum of all sessions for the day

### Scenario 5: Manager edits attendance
- **Activity logged**: Yes (who, when, reason)
- **Notification**: Employee notified of change
- **Audit trail**: Full history preserved

### Scenario 6: Internet connection lost during check-in
- **Frontend handling**: Show error, allow retry
- **Backend**: Transaction rollback if incomplete
- **Data integrity**: No partial records

### Scenario 7: Employee on approved leave checks in
- **Blocked**: System prevents check-in
- **Message**: "You are currently on approved leave"

### Scenario 8: Employee works night shift (cross-day session)
- **Allowed**: Yes, system supports cross-day work sessions
- **Example**: Check-in at 23:00 today, checkout at 06:00 tomorrow
- **Total hours**: Calculated correctly across day boundary
- **Manager visibility**: Can see active sessions from previous day

---

This completes the comprehensive feature documentation for the WFH Attendance & Task Tracking System.
