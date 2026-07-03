import { BrowserRouter, Routes, Route, Navigate } from 'react-router-dom';
import { Toaster } from 'react-hot-toast';
import { AuthProvider } from './contexts/AuthContext';
import { PrivateRoute } from './components/common/PrivateRoute';
import { ProtectedRegisterRoute } from './components/common/ProtectedRegisterRoute';
import { Loading } from './components/common/Loading';
import { useAuth } from './hooks/useAuth';

// Pages
import { LandingPage } from './pages/LandingPage';

// Auth Pages
import { Login } from './pages/auth/Login';
import { Register } from './pages/auth/Register';

// Employee Pages
import { EmployeeDashboard } from './pages/employee/Dashboard';
import { MyReport } from './pages/employee/MyReport';
import { MyLeave } from './pages/employee/MyLeave';
import { ChangePassword } from './pages/employee/ChangePassword';

// Manager Pages
import { ManagerDashboard } from './pages/manager/Dashboard';
import { UserManagement } from './pages/manager/UserManagement';
import { AttendanceManagement } from './pages/manager/AttendanceManagement';
import { DailyAttendanceReport } from './pages/manager/DailyAttendanceReport';
import { MonthlyAttendanceReport } from './pages/manager/MonthlyAttendanceReport';
import { CheckInTimeReport } from './pages/manager/CheckInTimeReport';
import { HolidayManagement } from './pages/manager/HolidayManagement';
import { LeaveApproval } from './pages/manager/LeaveApproval';
import { LeaveQuotaManagement } from './pages/manager/LeaveQuotaManagement';
import { ActivityLogs } from './pages/manager/ActivityLogs';
import { TeamSettings } from './pages/manager/TeamSettings';
import { WhatsAppSettings } from './pages/manager/WhatsAppSettings';

// Super Admin Pages
import { TeamManagement } from './pages/super-admin/TeamManagement';
import { SuperAdminUserManagement } from './pages/super-admin/UserManagement';

const RootRedirect = () => {
  const { user, loading } = useAuth();

  if (loading) {
    return <Loading fullScreen />;
  }

  if (!user) {
    return <LandingPage />;
  }

  // Redirect based on role
  if (user.role === 'super_admin') {
    return <Navigate to="/super-admin/teams" replace />;
  }

  if (user.role === 'manager') {
    return <Navigate to="/manager/dashboard" replace />;
  }

  return <Navigate to="/employee/dashboard" replace />;
};

function App() {
  return (
    <BrowserRouter>
      <AuthProvider>
        <Toaster
          position="top-right"
          toastOptions={{
            duration: 3000,
            style: {
              background: '#141414',
              color: '#ffffff',
              border: '1px solid #262626',
              borderRadius: '0px',
              fontFamily: "'EB Garamond', Garamond, serif",
              fontSize: '15px',
            },
            success: {
              duration: 3000,
              iconTheme: {
                primary: '#5fa657',
                secondary: '#000000',
              },
            },
            error: {
              duration: 4000,
              iconTheme: {
                primary: '#c0564b',
                secondary: '#000000',
              },
            },
          }}
        />

        <Routes>
          {/* Root */}
          <Route path="/" element={<RootRedirect />} />

          {/* Auth Routes */}
          <Route path="/login" element={<Login />} />
          <Route
            path="/register"
            element={
              <ProtectedRegisterRoute>
                <Register />
              </ProtectedRegisterRoute>
            }
          />

          {/* Employee Routes */}
          <Route
            path="/employee/dashboard"
            element={
              <PrivateRoute requiredRole="employee">
                <EmployeeDashboard />
              </PrivateRoute>
            }
          />
          <Route
            path="/employee/report"
            element={
              <PrivateRoute requiredRole="employee">
                <MyReport />
              </PrivateRoute>
            }
          />
          <Route
            path="/employee/leave"
            element={
              <PrivateRoute requiredRole="employee">
                <MyLeave />
              </PrivateRoute>
            }
          />
          <Route
            path="/employee/change-password"
            element={
              <PrivateRoute requiredRole="employee">
                <ChangePassword />
              </PrivateRoute>
            }
          />

          {/* Manager Routes */}
          <Route
            path="/manager/dashboard"
            element={
              <PrivateRoute requiredRole="manager">
                <ManagerDashboard />
              </PrivateRoute>
            }
          />
          <Route
            path="/manager/users"
            element={
              <PrivateRoute requiredRole="manager">
                <UserManagement />
              </PrivateRoute>
            }
          />
          <Route
            path="/manager/attendances"
            element={
              <PrivateRoute requiredRole="manager">
                <AttendanceManagement />
              </PrivateRoute>
            }
          />
          <Route
            path="/manager/daily-attendance-report"
            element={
              <PrivateRoute requiredRole="manager">
                <DailyAttendanceReport />
              </PrivateRoute>
            }
          />
          <Route
            path="/manager/monthly-attendance-report"
            element={
              <PrivateRoute requiredRole="manager">
                <MonthlyAttendanceReport />
              </PrivateRoute>
            }
          />
          <Route
            path="/manager/check-in-time-report"
            element={
              <PrivateRoute requiredRole="manager">
                <CheckInTimeReport />
              </PrivateRoute>
            }
          />
          <Route
            path="/manager/holidays"
            element={
              <PrivateRoute requiredRole="manager">
                <HolidayManagement />
              </PrivateRoute>
            }
          />
          <Route
            path="/manager/leaves"
            element={
              <PrivateRoute requiredRole="manager">
                <LeaveApproval />
              </PrivateRoute>
            }
          />
          <Route
            path="/manager/leave-quotas"
            element={
              <PrivateRoute requiredRole="manager">
                <LeaveQuotaManagement />
              </PrivateRoute>
            }
          />
          <Route
            path="/manager/activity-logs"
            element={
              <PrivateRoute requiredRole="manager">
                <ActivityLogs />
              </PrivateRoute>
            }
          />
          <Route
            path="/manager/team-settings"
            element={
              <PrivateRoute requiredRole="manager">
                <TeamSettings />
              </PrivateRoute>
            }
          />
          <Route
            path="/manager/whatsapp-settings"
            element={
              <PrivateRoute requiredRole="manager">
                <WhatsAppSettings />
              </PrivateRoute>
            }
          />

          {/* Super Admin Routes */}
          <Route
            path="/super-admin/teams"
            element={
              <PrivateRoute>
                <TeamManagement />
              </PrivateRoute>
            }
          />
          <Route
            path="/super-admin/users"
            element={
              <PrivateRoute>
                <SuperAdminUserManagement />
              </PrivateRoute>
            }
          />

          {/* 404 */}
          <Route path="*" element={<Navigate to="/" replace />} />
        </Routes>
      </AuthProvider>
    </BrowserRouter>
  );
}

export default App;
