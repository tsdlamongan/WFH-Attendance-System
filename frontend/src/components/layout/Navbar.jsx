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
    <nav className="fixed top-0 left-0 right-0 bg-white shadow-md z-40">
      <div className="px-4 sm:px-6 lg:px-8">
        <div className="flex items-center justify-between h-16">
          {/* Left side */}
          <div className="flex items-center">
            <button
              onClick={onMenuClick}
              className="lg:hidden p-2 rounded-md text-gray-600 hover:text-gray-900 hover:bg-gray-100"
            >
              <Menu size={24} />
            </button>
            <button
              onClick={onSidebarToggle}
              className="hidden lg:flex p-2 rounded-md text-gray-600 hover:text-gray-900 hover:bg-gray-100 mr-2 transition-transform"
              title={sidebarCollapsed ? "Tampilkan Sidebar" : "Sembunyikan Sidebar"}
            >
              {sidebarCollapsed ? (
                <ChevronRight size={24} />
              ) : (
                <ChevronLeft size={24} />
              )}
            </button>
            <h1 className="ml-2 text-xl font-bold text-primary-600">
              WFH
            </h1>
          </div>

          {/* Right side */}
          <div className="flex items-center space-x-2 sm:space-x-4">
            {/* Impersonation Indicator */}
            {isImpersonating && (
              <div className="flex items-center space-x-1 sm:space-x-2 px-2 sm:px-3 py-1 bg-yellow-100 border border-yellow-300 rounded-lg">
                <UserX size={14} className="text-yellow-700 sm:w-4 sm:h-4" />
                <span className="hidden sm:inline text-xs font-medium text-yellow-700">
                  Impersonating
                </span>
              </div>
            )}

            {/* User Info - Hidden on small screens */}
            <div className="hidden md:flex items-center space-x-2">
              <User size={20} className="text-gray-600" />
              <div className="text-sm">
                <p className="font-medium text-gray-900">{user?.name}</p>
                <p className="text-gray-500 capitalize">
                  {user?.role === 'super_admin' ? 'Super Admin' : user?.role}
                </p>
              </div>
            </div>

            {/* User Icon Only - Visible on small screens */}
            <div className="md:hidden flex items-center">
              <div className="p-2 bg-gray-100 rounded-full">
                <User size={18} className="text-gray-600" />
              </div>
            </div>

            {/* Stop Impersonate Button */}
            {isImpersonating && (
              <button
                onClick={handleStopImpersonate}
                className="flex items-center space-x-1 sm:space-x-2 px-2 sm:px-4 py-2 text-sm text-orange-600 hover:bg-orange-50 rounded-lg transition-colors"
                title="Stop Impersonate"
              >
                <UserX size={18} />
                <span className="hidden sm:inline">Stop</span>
              </button>
            )}

            {/* Logout Button */}
            <button
              onClick={handleLogout}
              className="flex items-center space-x-1 sm:space-x-2 px-2 sm:px-4 py-2 text-sm text-red-600 hover:bg-red-50 rounded-lg transition-colors"
              title="Logout"
            >
              <LogOut size={18} />
              <span className="hidden sm:inline">Keluar</span>
            </button>
          </div>
        </div>
      </div>
    </nav>
  );
};
