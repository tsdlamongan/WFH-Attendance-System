import { NavLink } from 'react-router-dom';
import {
  Home,
  Clock,
  FileText,
  Calendar,
  Users,
  Settings,
  ClipboardList,
  Activity,
  TrendingUp,
  X,
  Key,
  Building2,
  MessageCircle
} from 'lucide-react';
import { useAuth } from '../../hooks/useAuth';

export const Sidebar = ({ isOpen, onClose, collapsed = false }) => {
  const { isEmployee, isManager, isSuperAdmin } = useAuth();

  const employeeLinks = [
    { to: '/employee/dashboard', icon: Home, label: 'Dashboard' },
    { to: '/employee/report', icon: FileText, label: 'Laporan Saya' },
    { to: '/employee/leave', icon: Calendar, label: 'Pengajuan Cuti' },
    { to: '/employee/change-password', icon: Key, label: 'Ganti Password' },
  ];

  const managerLinks = [
    { to: '/manager/dashboard', icon: Home, label: 'Dashboard' },
    { to: '/manager/users', icon: Users, label: 'Pengguna' },
    { to: '/manager/attendances', icon: Clock, label: 'Absensi' },
    { to: '/manager/daily-attendance-report', icon: FileText, label: 'Laporan Harian' },
    { to: '/manager/monthly-attendance-report', icon: TrendingUp, label: 'Laporan Bulanan' },
    { to: '/manager/check-in-time-report', icon: Clock, label: 'Laporan Waktu Check-In' },
    { to: '/manager/leaves', icon: ClipboardList, label: 'Persetujuan Cuti' },
    { to: '/manager/leave-quotas', icon: Calendar, label: 'Jatah Cuti' },
    { to: '/manager/holidays', icon: Calendar, label: 'Hari Libur' },
    { to: '/manager/activity-logs', icon: Activity, label: 'Log Aktivitas' },
    { to: '/manager/whatsapp-settings', icon: MessageCircle, label: 'WhatsApp Gateway' },
  ];

  const superAdminLinks = [
    { to: '/super-admin/teams', icon: Building2, label: 'Manajemen Tim' },
    { to: '/super-admin/users', icon: Users, label: 'Manajemen Pengguna' },
  ];

  // Super admin gets super admin links, manager gets manager links with team settings, employee gets employee links
  const links = isSuperAdmin ? superAdminLinks : (isManager ? [...managerLinks, { to: '/manager/team-settings', icon: Building2, label: 'Pengaturan Tim' }] : employeeLinks);

  return (
    <>
      {/* Mobile backdrop */}
      {isOpen && (
        <div
          className="fixed inset-0 bg-ink/50 z-20 lg:hidden"
          onClick={onClose}
        ></div>
      )}

      {/* Sidebar */}
      <aside
        className={`fixed lg:fixed inset-y-0 left-0 z-30 w-64 bg-white border-r-2 border-ink transform transition-all duration-300 ease-in-out ${
          isOpen ? 'translate-x-0' : '-translate-x-full lg:translate-x-0'
        } ${
          collapsed ? 'lg:w-0 lg:overflow-hidden lg:border-r-0' : 'lg:w-64'
        }`}
        style={{ top: '64px', height: 'calc(100vh - 64px)' }}
      >
        <div className="h-full flex flex-col">
          {/* Close button for mobile */}
          <div className="lg:hidden flex justify-end p-4">
            <button onClick={onClose} className="text-muted hover:text-ink transition-colors">
              <X size={22} />
            </button>
          </div>

          {/* Navigation */}
          <nav className={`flex-1 px-4 py-6 space-y-1 overflow-y-auto ${collapsed ? 'lg:hidden' : ''}`}>
            {links.map((link) => (
              <NavLink
                key={link.to}
                to={link.to}
                onClick={onClose}
                className={({ isActive }) =>
                  `flex items-center space-x-3 px-4 py-3 rounded-xl font-display text-nav-link font-semibold transition-all ${
                    isActive
                      ? 'border-2 border-ink bg-primary text-white shadow-brutal-sm'
                      : 'border-2 border-transparent text-body hover:bg-surface-elevated hover:border-hairline'
                  }`
                }
              >
                <link.icon size={16} />
                <span>{link.label}</span>
              </NavLink>
            ))}
          </nav>
        </div>
      </aside>
    </>
  );
};
