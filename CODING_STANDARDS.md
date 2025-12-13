# CODING STANDARDS

## Overview
This document outlines the coding standards and best practices for the WFH Attendance System project. All developers and AI assistants must follow these guidelines to ensure code consistency, maintainability, and performance.

---

## General Principles

1. **Write Clean Code**: Code should be self-documenting with clear variable and function names
2. **DRY Principle**: Don't Repeat Yourself - extract reusable logic
3. **SOLID Principles**: Follow SOLID design principles for OOP
4. **Security First**: Always validate input, sanitize output, and prevent common vulnerabilities
5. **Performance**: Optimize database queries and minimize API calls
6. **Testing**: Write tests for critical business logic

---

## Backend (Laravel) Standards

### File and Class Naming Conventions

#### Controllers
- **Naming**: PascalCase, singular noun + "Controller"
- **Example**: `AttendanceController`, `UserManagementController`
- **Location**: `app/Http/Controllers/Api/`

```php
// ✅ GOOD
class AttendanceController extends Controller
{
    public function checkIn(CheckInRequest $request) { }
}

// ❌ BAD
class attendanceController extends Controller
{
    public function CheckIn($request) { }
}
```

#### Models
- **Naming**: PascalCase, singular noun
- **Example**: `User`, `Attendance`, `Task`
- **Location**: `app/Models/`

```php
// ✅ GOOD
class Attendance extends Model
{
    protected $fillable = ['user_id', 'check_in', 'check_out', 'date'];
}

// ❌ BAD
class attendances extends Model
{
    public $fillable = ['user_id'];
}
```

#### Services
- **Naming**: PascalCase, noun + "Service"
- **Example**: `AttendanceService`, `ReportService`
- **Location**: `app/Services/`
- **Purpose**: Business logic, orchestrate repositories

```php
// ✅ GOOD
class AttendanceService
{
    public function __construct(
        private AttendanceRepository $attendanceRepository,
        private ActivityLogService $activityLogService
    ) {}

    public function checkIn(User $user, array $tasks): Attendance
    {
        // Business logic here
    }
}
```

#### Repositories
- **Naming**: PascalCase, noun + "Repository"
- **Example**: `AttendanceRepository`, `UserRepository`
- **Location**: `app/Repositories/`
- **Purpose**: Data access layer only

```php
// ✅ GOOD
class AttendanceRepository
{
    public function findByUserAndDate(int $userId, Carbon $date): ?Attendance
    {
        return Attendance::where('user_id', $userId)
            ->whereDate('date', $date)
            ->first();
    }
}
```

#### Requests (Form Validation)
- **Naming**: PascalCase, action/noun + "Request"
- **Example**: `CheckInRequest`, `UpdateUserRequest`
- **Location**: `app/Http/Requests/`

```php
// ✅ GOOD
class CheckInRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->check();
    }

    public function rules(): array
    {
        return [
            'tasks' => 'required|array|min:1',
            'tasks.*.title' => 'required|string|max:255',
        ];
    }
}
```

#### Resources (API Responses)
- **Naming**: PascalCase, noun + "Resource"
- **Example**: `AttendanceResource`, `UserResource`
- **Location**: `app/Http/Resources/`

```php
// ✅ GOOD
class AttendanceResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'check_in' => $this->check_in->toIso8601String(),
            'check_out' => $this->check_out?->toIso8601String(),
            'total_hours' => $this->total_hours,
            'tasks' => TaskResource::collection($this->whenLoaded('tasks')),
        ];
    }
}
```

### Method Naming Conventions

#### Controller Methods
- **REST Convention**: Use standard HTTP verbs
- **camelCase**: Always use camelCase for method names

```php
// ✅ GOOD
public function index()      // GET - List all
public function store()      // POST - Create
public function show($id)    // GET - Show one
public function update($id)  // PUT/PATCH - Update
public function destroy($id) // DELETE - Delete

// Custom actions
public function checkIn(CheckInRequest $request)
public function checkOut(CheckOutRequest $request)

// ❌ BAD
public function CheckIn()
public function check_in()
```

#### Service Methods
- **Descriptive names**: Use verb + noun pattern

```php
// ✅ GOOD
public function calculateTotalHours(Carbon $checkIn, Carbon $checkOut): float
public function getEmployeeReport(int $userId, Carbon $startDate, Carbon $endDate): array
public function checkInEmployee(User $user, array $tasks): Attendance

// ❌ BAD
public function calc()
public function getReport()
```

### Database Query Standards

#### Use Query Builder (Preferred)
```php
// ✅ GOOD - Use Query Builder with proper indexing
Attendance::where('user_id', $userId)
    ->whereDate('date', $date)
    ->with('tasks')
    ->first();

// ✅ GOOD - Use eager loading to prevent N+1
User::with('attendances.tasks')->get();

// ❌ BAD - N+1 query problem
$users = User::all();
foreach ($users as $user) {
    $user->attendances; // This triggers additional queries
}
```

#### Avoid Raw Queries Unless Necessary
```php
// ❌ AVOID unless absolutely necessary
DB::select("SELECT * FROM users WHERE email = ?", [$email]);

// ✅ GOOD
User::where('email', $email)->first();
```

#### Always Use Parameter Binding
```php
// ✅ GOOD
DB::table('users')->where('email', $email)->get();

// ❌ NEVER - SQL Injection risk
DB::select("SELECT * FROM users WHERE email = '$email'");
```

### Validation Rules

#### Always Validate Input
```php
// ✅ GOOD
public function rules(): array
{
    return [
        'email' => 'required|email|unique:users,email',
        'password' => 'required|min:8|confirmed',
        'tasks' => 'required|array|min:1|max:20',
        'tasks.*.title' => 'required|string|max:255',
    ];
}
```

#### Custom Validation Messages
```php
public function messages(): array
{
    return [
        'tasks.required' => 'You must provide at least one task.',
        'tasks.*.title.required' => 'Each task must have a title.',
    ];
}
```

### Error Handling

#### Use Try-Catch for Critical Operations
```php
// ✅ GOOD
public function checkIn(CheckInRequest $request): JsonResponse
{
    try {
        $attendance = $this->attendanceService->checkIn(
            auth()->user(),
            $request->validated()['tasks']
        );

        return response()->json([
            'success' => true,
            'data' => new AttendanceResource($attendance),
        ], 201);
    } catch (\Exception $e) {
        Log::error('Check-in failed: ' . $e->getMessage());
        
        return response()->json([
            'success' => false,
            'message' => 'Failed to check in. Please try again.',
        ], 500);
    }
}
```

#### Return Consistent JSON Responses
```php
// ✅ GOOD - Success response
return response()->json([
    'success' => true,
    'data' => $data,
    'message' => 'Operation successful',
], 200);

// ✅ GOOD - Error response
return response()->json([
    'success' => false,
    'message' => 'Validation failed',
    'errors' => $validator->errors(),
], 422);
```

### Security Standards

#### Authentication & Authorization
```php
// ✅ GOOD - Use middleware for route protection
Route::middleware(['auth:sanctum', 'role:manager'])->group(function () {
    Route::get('/users', [UserManagementController::class, 'index']);
});

// ✅ GOOD - Check permissions in controller
public function destroy(User $user)
{
    if (auth()->user()->role !== UserRole::MANAGER) {
        abort(403, 'Unauthorized action.');
    }
    
    $user->delete();
}
```

#### Prevent Mass Assignment
```php
// ✅ GOOD
protected $fillable = ['name', 'email', 'role'];
protected $guarded = ['id', 'password'];

// ❌ BAD
protected $guarded = [];
```

#### Hash Passwords
```php
// ✅ GOOD
User::create([
    'name' => $request->name,
    'email' => $request->email,
    'password' => Hash::make($request->password),
]);

// ❌ BAD
User::create([
    'password' => $request->password, // Plain text!
]);
```

### Performance Optimization

#### Eager Loading
```php
// ✅ GOOD
$attendances = Attendance::with(['user', 'tasks'])
    ->whereBetween('date', [$startDate, $endDate])
    ->get();

// ❌ BAD - N+1 problem
$attendances = Attendance::all();
foreach ($attendances as $attendance) {
    $attendance->user; // Lazy loading
    $attendance->tasks; // Lazy loading
}
```

#### Use Chunking for Large Datasets
```php
// ✅ GOOD
Attendance::chunk(100, function ($attendances) {
    foreach ($attendances as $attendance) {
        // Process
    }
});
```

#### Cache Expensive Queries
```php
// ✅ GOOD
$holidays = Cache::remember('holidays_2024', 3600, function () {
    return Holiday::whereYear('date', 2024)->get();
});
```

#### Database Indexing
```php
// ✅ GOOD - Add indexes in migrations
Schema::create('attendances', function (Blueprint $table) {
    $table->id();
    $table->foreignId('user_id')->constrained()->onDelete('cascade');
    $table->dateTime('check_in');
    $table->dateTime('check_out')->nullable();
    $table->date('date');
    $table->decimal('total_hours', 5, 2)->default(0);
    $table->timestamps();
    
    // Indexes for performance
    $table->index(['user_id', 'date']);
    $table->index('date');
});
```

### Logging Standards

#### Log Important Events
```php
// ✅ GOOD
Log::info('User checked in', [
    'user_id' => $user->id,
    'check_in' => $checkIn,
]);

Log::error('Checkout failed', [
    'attendance_id' => $attendance->id,
    'error' => $e->getMessage(),
]);
```

---

## Frontend (React) Standards

### File and Component Naming

#### Components
- **PascalCase** for component files
- **Example**: `CheckInModal.jsx`, `UserManagement.jsx`

```jsx
// ✅ GOOD - CheckInModal.jsx
export const CheckInModal = ({ isOpen, onClose }) => {
    return <div>...</div>;
};

// ❌ BAD - checkInModal.jsx
export const checkInModal = ({ isOpen, onClose }) => {
    return <div>...</div>;
};
```

#### Hooks
- **camelCase** starting with "use"
- **Example**: `useAuth.js`, `useAttendance.js`

```javascript
// ✅ GOOD - useAuth.js
export const useAuth = () => {
    const [user, setUser] = useState(null);
    // ...
    return { user, login, logout };
};
```

#### API Functions
- **camelCase** with descriptive names
- **Example**: `checkIn`, `getUserReport`, `updateTask`

```javascript
// ✅ GOOD - attendance.api.js
export const checkIn = async (tasks) => {
    const response = await apiClient.post('/attendance/check-in', { tasks });
    return response.data;
};

export const getUserReport = async (userId, startDate, endDate) => {
    const response = await apiClient.get(`/reports/user/${userId}`, {
        params: { start_date: startDate, end_date: endDate }
    });
    return response.data;
};
```

### Component Structure

#### Functional Components with Hooks
```jsx
// ✅ GOOD
import { useState, useEffect } from 'react';
import { useAuth } from '@/hooks/useAuth';

export const Dashboard = () => {
    const { user } = useAuth();
    const [attendances, setAttendances] = useState([]);
    const [loading, setLoading] = useState(true);

    useEffect(() => {
        fetchAttendances();
    }, []);

    const fetchAttendances = async () => {
        try {
            setLoading(true);
            const data = await getAttendances();
            setAttendances(data);
        } catch (error) {
            console.error('Failed to fetch attendances', error);
        } finally {
            setLoading(false);
        }
    };

    if (loading) return <Loading />;

    return (
        <div className="dashboard">
            {/* Component JSX */}
        </div>
    );
};
```

### State Management

#### Use Context for Global State
```javascript
// ✅ GOOD - AuthContext.jsx
export const AuthContext = createContext(null);

export const AuthProvider = ({ children }) => {
    const [user, setUser] = useState(null);
    const [loading, setLoading] = useState(true);

    const login = async (email, password) => {
        const data = await loginApi(email, password);
        setUser(data.user);
        localStorage.setItem('token', data.token);
    };

    const logout = () => {
        setUser(null);
        localStorage.removeItem('token');
    };

    return (
        <AuthContext.Provider value={{ user, login, logout, loading }}>
            {children}
        </AuthContext.Provider>
    );
};
```

#### Local State for Component-Specific Data
```jsx
// ✅ GOOD
const [isModalOpen, setIsModalOpen] = useState(false);
const [selectedTask, setSelectedTask] = useState(null);
```

### API Calls

#### Centralized Axios Instance
```javascript
// ✅ GOOD - axios.js
import axios from 'axios';

const apiClient = axios.create({
    baseURL: import.meta.env.VITE_API_URL,
    headers: {
        'Content-Type': 'application/json',
        'Accept': 'application/json',
    },
});

// Request interceptor
apiClient.interceptors.request.use(
    (config) => {
        const token = localStorage.getItem('token');
        if (token) {
            config.headers.Authorization = `Bearer ${token}`;
        }
        return config;
    },
    (error) => Promise.reject(error)
);

// Response interceptor
apiClient.interceptors.response.use(
    (response) => response,
    (error) => {
        if (error.response?.status === 401) {
            localStorage.removeItem('token');
            window.location.href = '/login';
        }
        return Promise.reject(error);
    }
);

export default apiClient;
```

### Error Handling

#### Use Try-Catch with User Feedback
```jsx
// ✅ GOOD
const handleCheckIn = async () => {
    try {
        setLoading(true);
        await checkIn(tasks);
        toast.success('Checked in successfully!');
        navigate('/dashboard');
    } catch (error) {
        toast.error(error.response?.data?.message || 'Failed to check in');
    } finally {
        setLoading(false);
    }
};
```

### Form Handling

#### Use React Hook Form
```jsx
// ✅ GOOD
import { useForm } from 'react-hook-form';

export const CheckInForm = ({ onSubmit }) => {
    const { register, handleSubmit, formState: { errors } } = useForm();

    return (
        <form onSubmit={handleSubmit(onSubmit)}>
            <input
                {...register('task', {
                    required: 'Task is required',
                    maxLength: { value: 255, message: 'Max 255 characters' }
                })}
            />
            {errors.task && <span>{errors.task.message}</span>}
        </form>
    );
};
```

### Performance Optimization

#### Memoization
```jsx
// ✅ GOOD - Use useMemo for expensive calculations
const totalHours = useMemo(() => {
    return attendances.reduce((sum, att) => sum + att.total_hours, 0);
}, [attendances]);

// ✅ GOOD - Use useCallback for functions passed as props
const handleDelete = useCallback((id) => {
    deleteTask(id);
}, []);
```

#### Lazy Loading Routes
```javascript
// ✅ GOOD
import { lazy, Suspense } from 'react';

const Dashboard = lazy(() => import('./pages/employee/Dashboard'));
const Reports = lazy(() => import('./pages/manager/Reports'));

// In routes
<Suspense fallback={<Loading />}>
    <Dashboard />
</Suspense>
```

---

## Code Review Checklist

### Before Committing
- [ ] Code follows naming conventions
- [ ] No console.log() in production code
- [ ] All variables have meaningful names
- [ ] Functions are small and do one thing
- [ ] No hardcoded values (use constants/env)
- [ ] Error handling is implemented
- [ ] Security vulnerabilities checked
- [ ] Performance optimizations applied
- [ ] Comments added for complex logic
- [ ] Tests written for critical features

### Git Commit Messages
```
// ✅ GOOD
feat: Add check-in functionality with task management
fix: Resolve checkout timing issue
refactor: Extract attendance calculation to service layer
docs: Update API documentation for new endpoints

// ❌ BAD
update
fix bug
changes
```

---

## Testing Standards

### Backend Tests
```php
// ✅ GOOD
public function test_user_can_check_in_with_valid_tasks()
{
    $user = User::factory()->create(['role' => UserRole::EMPLOYEE]);
    
    $response = $this->actingAs($user)
        ->postJson('/api/v1/attendance/check-in', [
            'tasks' => [
                ['title' => 'Complete feature X'],
                ['title' => 'Fix bug Y'],
            ]
        ]);
    
    $response->assertStatus(201)
        ->assertJsonStructure(['success', 'data']);
}
```

### Frontend Tests
```javascript
// ✅ GOOD
test('renders check-in button when not checked in', () => {
    render(<Dashboard />);
    expect(screen.getByText('Check In')).toBeInTheDocument();
});
```

---

## Documentation Standards

### Code Comments
```php
// ✅ GOOD
/**
 * Calculate total working hours between check-in and check-out
 * 
 * @param Carbon $checkIn
 * @param Carbon $checkOut
 * @return float Total hours in decimal format (e.g., 7.5)
 */
public function calculateTotalHours(Carbon $checkIn, Carbon $checkOut): float
{
    return round($checkIn->diffInMinutes($checkOut) / 60, 2);
}
```

### API Documentation
- Document all endpoints with examples
- Include request/response formats
- Specify authentication requirements
- List possible error codes
