import { LogOut, User, Menu, ChevronLeft, ChevronRight, UserX } from 'lucide-react';
import { useAuth } from '../../hooks/useAuth';
import { useNavigate } from 'react-router-dom';
import { stopImpersonate } from '../../api/team.api';
import toast from 'react-hot-toast';

export const Navbar = ({ onMenuClick, onSidebarToggle, sidebarCollapsed = false }) => {
  const { user, logout, setSession } = useAuth();
  const navigate = useNavigate();

  // Prefer localStorage so impersonation state survives tab close / long idle
  const isImpersonating =
    sessionStorage.getItem('is_impersonating') === 'true' ||
    localStorage.getItem('is_impersonating') === 'true';
  const originalUserId =
    sessionStorage.getItem('original_user_id') || localStorage.getItem('original_user_id');

  const clearImpersonationState = () => {
    sessionStorage.removeItem('original_user_id');
    sessionStorage.removeItem('is_impersonating');
    localStorage.removeItem('original_user_id');
    localStorage.removeItem('is_impersonating');
  };

  const handleLogout = async () => {
    clearImpersonationState();
    await logout();
    navigate('/login');
  };

  const handleStopImpersonate = async () => {
    const originalId = originalUserId ? parseInt(originalUserId, 10) : NaN;
    if (!originalUserId || Number.isNaN(originalId)) {
      toast.error('Data sesi impersonasi tidak ditemukan. Silakan login kembali sebagai Super Admin.', {
        duration: 6000,
      });
      clearImpersonationState();
      window.location.href = '/login';
      return;
    }

    try {
      const response = await stopImpersonate(originalId);

      if (response.success) {
        const { user: superAdmin, token } = response.data;

        clearImpersonationState();

        setSession(superAdmin, token);

        toast.success('Stopped impersonating');

        window.location.href = '/super-admin/teams';
      }
    } catch (error) {
      if (error.response?.status === 401) {
        toast.error('Sesi impersonasi telah berakhir. Silakan login kembali sebagai Super Admin.', {
          duration: 5000,
        });
        clearImpersonationState();
        localStorage.removeItem('token');
        localStorage.removeItem('user');
        setTimeout(() => {
          window.location.href = '/login';
        }, 800);
        return;
      }
      const message = error.response?.data?.message || 'Failed to stop impersonation';
      toast.error(message);
    }
  };

  return (
    <nav className="fixed top-0 left-0 right-0 bg-white border-b-2 border-ink z-40">
      <div className="px-4 sm:px-6 lg:px-8">
        <div className="flex items-center justify-between h-16">
          {/* Left side */}
          <div className="flex items-center">
            <button
              onClick={onMenuClick}
              className="lg:hidden p-2 text-muted hover:text-ink transition-colors"
            >
              <Menu size={22} />
            </button>
            <button
              onClick={onSidebarToggle}
              className="hidden lg:flex p-2 text-muted hover:text-ink mr-2 transition-colors"
              title={sidebarCollapsed ? "Tampilkan Sidebar" : "Sembunyikan Sidebar"}
            >
              {sidebarCollapsed ? (
                <ChevronRight size={22} />
              ) : (
                <ChevronLeft size={22} />
              )}
            </button>
            <h1 className="ml-2 flex items-center gap-2 font-display font-extrabold text-wordmark text-ink">
              <span className="inline-flex h-7 w-7 items-center justify-center rounded-lg border-2 border-ink bg-primary text-sm text-white shadow-brutal-sm">W</span>
              WFH
            </h1>
          </div>

          {/* Right side */}
          <div className="flex items-center space-x-2 sm:space-x-4">
            {/* Impersonation Indicator */}
            {isImpersonating && (
              <div className="flex items-center space-x-1 sm:space-x-2 px-2 sm:px-3 py-1 border-2 border-ink rounded-full bg-warning/20 shadow-brutal-sm">
                <UserX size={14} className="text-ink sm:w-4 sm:h-4" />
                <span className="hidden sm:inline font-display text-caption uppercase text-ink">
                  Impersonating
                </span>
              </div>
            )}

            {/* User Info - Hidden on small screens */}
            <div className="hidden md:flex items-center space-x-3">
              <User size={18} className="text-muted" />
              <div className="text-sm">
                <p className="font-display font-semibold text-body-strong">{user?.name}</p>
                <p className="font-display text-caption uppercase text-muted capitalize">
                  {user?.role === 'super_admin' ? 'Super Admin' : user?.role}
                </p>
              </div>
            </div>

            {/* User Icon Only - Visible on small screens */}
            <div className="md:hidden flex items-center">
              <div className="flex items-center justify-center w-10 h-10 rounded-full border-2 border-ink">
                <User size={16} className="text-ink" />
              </div>
            </div>

            {/* Stop Impersonate Button */}
            {isImpersonating && (
              <button
                onClick={handleStopImpersonate}
                className="flex items-center space-x-1 sm:space-x-2 px-2 sm:px-4 py-2 font-display text-nav-link font-semibold text-error hover:opacity-70 transition-opacity"
                title="Stop Impersonate"
              >
                <UserX size={16} />
                <span className="hidden sm:inline">Stop</span>
              </button>
            )}

            {/* Logout Button */}
            <button
              onClick={handleLogout}
              className="flex items-center space-x-1 sm:space-x-2 px-2 sm:px-4 py-2 font-display text-nav-link font-semibold text-muted hover:text-ink transition-colors"
              title="Logout"
            >
              <LogOut size={16} />
              <span className="hidden sm:inline">Keluar</span>
            </button>
          </div>
        </div>
      </div>
    </nav>
  );
};
