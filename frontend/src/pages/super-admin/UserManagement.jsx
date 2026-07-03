import { useState, useEffect } from 'react';
import { MainLayout } from '../../components/layout/MainLayout';
import { Card } from '../../components/common/Card';
import { Button } from '../../components/common/Button';
import { Input } from '../../components/common/Input';
import { Loading } from '../../components/common/Loading';
import { Modal } from '../../components/common/Modal';
import { Pagination } from '../../components/common/Pagination';
import { getAllUsers, createUser, updateUser, deleteUser, toggleUserDisabled } from '../../api/manager.api';
import { getAllTeams, impersonateUser } from '../../api/team.api';
import { formatDate } from '../../utils/dateHelpers';
import { usePageTitle } from '../../hooks/usePageTitle';
import { useAuth } from '../../hooks/useAuth';
import { Users, Plus, Edit, Trash2, Shield, User, UserCheck, Building2, Ban, CheckCircle } from 'lucide-react';
import toast from 'react-hot-toast';

export const SuperAdminUserManagement = () => {
  usePageTitle('Manajemen Pengguna');
  const { setSession } = useAuth();
  const [loading, setLoading] = useState(true);
  const [users, setUsers] = useState([]);
  const [teams, setTeams] = useState([]);
  const [showModal, setShowModal] = useState(false);
  const [editingUser, setEditingUser] = useState(null);
  const [submitting, setSubmitting] = useState(false);
  const [pagination, setPagination] = useState({
    current_page: 1,
    last_page: 1,
    per_page: 10,
    total: 0,
    from: 0,
    to: 0,
  });

  const [formData, setFormData] = useState({
    name: '',
    email: '',
    password: '',
    password_confirmation: '',
    role: 'employee',
    team_id: '',
    leave_quota_days: 12,
  });

  useEffect(() => {
    fetchTeams();
    fetchUsers(1, 10);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  const fetchTeams = async () => {
    try {
      const response = await getAllTeams(1000);
      if (response.success) {
        setTeams(response.data);
      }
    } catch (error) {
      console.error('Error fetching teams:', error);
    }
  };

  const fetchUsers = async (page = 1, perPage = 10) => {
    try {
      setLoading(true);
      const response = await getAllUsers(page, perPage);

      if (response.success) {
        setUsers(response.data);
        if (response.pagination) {
          setPagination(response.pagination);
        }
      }
    } catch (error) {
      console.error('Error fetching users:', error);
      toast.error('Failed to fetch users');
    } finally {
      setLoading(false);
    }
  };

  const handlePageChange = (page) => {
    fetchUsers(page, pagination.per_page);
  };

  const handlePerPageChange = (perPage) => {
    fetchUsers(1, perPage);
  };

  const handleOpenModal = (user = null) => {
    if (user) {
      setEditingUser(user);
      setFormData({
        name: user.name,
        email: user.email,
        password: '',
        password_confirmation: '',
        role: user.role,
        team_id: user.team_id || '',
        leave_quota_days: user.leave_quota_days || 12,
      });
    } else {
      setEditingUser(null);
      setFormData({
        name: '',
        email: '',
        password: '',
        password_confirmation: '',
        role: 'employee',
        team_id: '',
        leave_quota_days: 12,
      });
    }
    setShowModal(true);
  };

  const handleCloseModal = () => {
    setShowModal(false);
    setEditingUser(null);
  };

  const handleSubmit = async (e) => {
    e.preventDefault();

    if (!formData.team_id) {
      toast.error('Silakan pilih tim');
      return;
    }

    if (!editingUser && formData.password.length < 8) {
      toast.error('Password minimal 8 karakter');
      return;
    }

    if (!editingUser && formData.password !== formData.password_confirmation) {
      toast.error('Konfirmasi password tidak cocok');
      return;
    }

    try {
      setSubmitting(true);

      const userData = {
        name: formData.name,
        email: formData.email,
        role: formData.role,
        team_id: parseInt(formData.team_id),
        leave_quota_days: parseInt(formData.leave_quota_days) || 12,
      };

      if (formData.password) {
        userData.password = formData.password;
        userData.password_confirmation = formData.password_confirmation;
      }

      let response;
      if (editingUser) {
        response = await updateUser(editingUser.id, userData);
      } else {
        response = await createUser(userData);
      }

      if (response.success) {
        toast.success(response.message);
        handleCloseModal();
        fetchUsers();
      }
    } catch (error) {
      const message = error.response?.data?.message || 'Operasi gagal';
      toast.error(message);
    } finally {
      setSubmitting(false);
    }
  };

  const handleDelete = async (userId, userName) => {
    if (!confirm(`Are you sure you want to delete ${userName}?`)) {
      return;
    }

    try {
      const response = await deleteUser(userId);

      if (response.success) {
        toast.success(response.message);
        fetchUsers();
      }
    } catch (error) {
      const message = error.response?.data?.message || 'Failed to delete user';
      toast.error(message);
    }
  };

  const handleToggleDisabled = async (user) => {
    const action = user.is_disabled ? 'mengaktifkan kembali' : 'menonaktifkan';
    if (!confirm(`Yakin ingin ${action} ${user.name}?`)) {
      return;
    }

    try {
      const response = await toggleUserDisabled(user.id);
      if (response.success) {
        toast.success(response.message);
        fetchUsers(pagination.current_page, pagination.per_page);
      }
    } catch (error) {
      const message = error.response?.data?.message || 'Gagal mengubah status pengguna';
      toast.error(message);
    }
  };

  const handleImpersonate = async (user) => {
    if (user.role === 'super_admin') {
      toast.error('Cannot impersonate another super admin');
      return;
    }

    if (user.is_disabled) {
      toast.error('Tidak dapat impersonate user yang dinonaktifkan');
      return;
    }

    if (!confirm(`Impersonate as ${user.name}?`)) {
      return;
    }

    try {
      const response = await impersonateUser(user.id);

      if (response.success) {
        const { user: impersonatedUser, token, original_user_id } = response.data;

        // Store in both sessionStorage and localStorage so impersonation state
        // survives tab close / long idle (stop-impersonate still needs original_user_id)
        const idStr = String(original_user_id);
        sessionStorage.setItem('original_user_id', idStr);
        sessionStorage.setItem('is_impersonating', 'true');
        localStorage.setItem('original_user_id', idStr);
        localStorage.setItem('is_impersonating', 'true');

        // Update auth context with impersonated user
        setSession(impersonatedUser, token);

        toast.success(`Now impersonating ${user.name}`);

        // Redirect based on impersonated user role
        window.location.href = impersonatedUser.role === 'manager'
          ? '/manager/dashboard'
          : '/employee/dashboard';
      }
    } catch (error) {
      const message = error.response?.data?.message || 'Failed to impersonate user';
      toast.error(message);
    }
  };

  const getTeamName = (user) => {
    // First try to get team name from user.team object (already loaded)
    if (user.team && user.team.name) {
      return user.team.name;
    }
    // Fallback to finding in teams list
    if (user.team_id) {
      const team = teams.find(t => t.id === user.team_id);
      return team ? team.name : 'N/A';
    }
    return '-';
  };

  const getRoleBadge = (role) => {
    if (role === 'super_admin') {
      return <span className="badge badge-warning">Super Admin</span>;
    }
    if (role === 'manager') {
      return <span className="badge badge-info">Manager</span>;
    }
    return <span className="badge badge-success">Employee</span>;
  };

  const getRoleIcon = (role) => {
    if (role === 'super_admin') {
      return <Shield size={20} className="text-muted" />;
    }
    if (role === 'manager') {
      return <Shield size={20} className="text-muted" />;
    }
    return <User size={20} className="text-muted" />;
  };

  if (loading) {
    return (
      <MainLayout>
        <Loading />
      </MainLayout>
    );
  }

  return (
    <MainLayout>
      <div className="space-y-6 sm:space-y-8">
        {/* Header */}
        <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
          <div>
            <h1 className="text-display-md sm:text-display-lg">Manajemen Pengguna</h1>
            <p className="mt-2 font-serif text-body">Kelola semua pengguna dalam sistem</p>
          </div>
          <Button
            onClick={() => handleOpenModal()}
            className="flex items-center space-x-2"
          >
            <Plus size={20} />
            <span>Tambah Pengguna</span>
          </Button>
        </div>

        {/* Users List */}
        <Card>
          {users.length === 0 ? (
            <div className="text-center py-16">
              <Users size={48} className="mx-auto text-muted mb-4" />
              <p className="caption-uppercase">Tidak ada pengguna ditemukan</p>
            </div>
          ) : (
            <div className="overflow-x-auto">
              <table className="w-full">
                <thead>
                  <tr className="border-b border-hairline">
                    <th className="caption-uppercase text-left px-4 py-3">
                      Pengguna
                    </th>
                    <th className="caption-uppercase text-left px-4 py-3">
                      Tim
                    </th>
                    <th className="caption-uppercase text-left px-4 py-3">
                      Peran
                    </th>
                    <th className="caption-uppercase text-left px-4 py-3">
                      Jatah Cuti
                    </th>
                    <th className="caption-uppercase text-left px-4 py-3">
                      Status
                    </th>
                    <th className="caption-uppercase text-left px-4 py-3">
                      Dibuat Pada
                    </th>
                    <th className="caption-uppercase text-right px-4 py-3">
                      Aksi
                    </th>
                  </tr>
                </thead>
                <tbody>
                  {users.map((user) => (
                    <tr key={user.id} className={`border-b border-hairline last:border-0 hover:bg-surface-soft transition-colors ${user.is_disabled ? 'opacity-60' : ''}`}>
                      <td className="px-4 py-3 whitespace-nowrap">
                        <div className="flex items-center space-x-3">
                          <div className="w-10 h-10 rounded-full border border-hairline-strong flex items-center justify-center">
                            {getRoleIcon(user.role)}
                          </div>
                          <div>
                            <p className="font-serif text-body-strong">{user.name}</p>
                            <p className="font-mono text-xs text-muted">{user.email}</p>
                          </div>
                        </div>
                      </td>
                      <td className="px-4 py-3 whitespace-nowrap">
                        <div className="flex items-center font-serif text-sm text-body">
                          <Building2 size={16} className="mr-1 text-muted" />
                          {getTeamName(user)}
                        </div>
                      </td>
                      <td className="px-4 py-3 whitespace-nowrap">
                        {getRoleBadge(user.role)}
                      </td>
                      <td className="px-4 py-3 whitespace-nowrap">
                        <span className="font-mono text-sm text-ink">
                          {user.leave_quota_days || 0} hari
                        </span>
                      </td>
                      <td className="px-4 py-3 whitespace-nowrap">
                        <span className={`badge ${user.is_disabled ? 'badge-danger' : 'badge-success'}`}>
                          {user.is_disabled ? 'Dinonaktifkan' : 'Aktif'}
                        </span>
                      </td>
                      <td className="px-4 py-3 whitespace-nowrap font-mono text-sm text-muted">
                        {formatDate(user.created_at)}
                      </td>
                      <td className="px-4 py-3 whitespace-nowrap text-right">
                        <div className="flex justify-end space-x-2">
                          {user.role !== 'super_admin' && !user.is_disabled && (
                            <button
                              onClick={() => handleImpersonate(user)}
                              className="p-2 text-muted hover:text-success transition-colors"
                              title="Impersonate"
                            >
                              <UserCheck size={18} />
                            </button>
                          )}
                          <button
                            onClick={() => handleOpenModal(user)}
                            className="p-2 text-muted hover:text-ink transition-colors"
                            title="Edit"
                          >
                            <Edit size={18} />
                          </button>
                          {user.role !== 'super_admin' && (
                            <button
                              onClick={() => handleToggleDisabled(user)}
                              className={user.is_disabled ? 'p-2 text-muted hover:text-success transition-colors' : 'p-2 text-muted hover:text-warning transition-colors'}
                              title={user.is_disabled ? 'Aktifkan' : 'Nonaktifkan'}
                            >
                              {user.is_disabled ? <CheckCircle size={18} /> : <Ban size={18} />}
                            </button>
                          )}
                          {user.role !== 'super_admin' && (
                            <button
                              onClick={() => handleDelete(user.id, user.name)}
                              className="p-2 text-muted hover:text-error transition-colors"
                              title="Delete"
                            >
                              <Trash2 size={18} />
                            </button>
                          )}
                        </div>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}

          {users.length > 0 && (
            <Pagination
              currentPage={pagination.current_page}
              lastPage={pagination.last_page}
              perPage={pagination.per_page}
              total={pagination.total}
              from={pagination.from}
              to={pagination.to}
              onPageChange={handlePageChange}
              onPerPageChange={handlePerPageChange}
            />
          )}
        </Card>
      </div>

      {/* Add/Edit User Modal */}
      <Modal
        isOpen={showModal}
        onClose={handleCloseModal}
        title={editingUser ? 'Edit Pengguna' : 'Tambah Pengguna Baru'}
        size="md"
      >
        <form onSubmit={handleSubmit}>
          <Input
            label="Nama"
            value={formData.name}
            onChange={(e) => setFormData({ ...formData, name: e.target.value })}
            required
          />

          <Input
            label="Email"
            type="email"
            value={formData.email}
            onChange={(e) => setFormData({ ...formData, email: e.target.value })}
            required
          />

          <div className="mb-6">
            <label className="caption-uppercase block mb-2">
              Tim <span className="text-error">*</span>
            </label>
            <select
              value={formData.team_id}
              onChange={(e) => setFormData({ ...formData, team_id: e.target.value })}
              className="input-field"
              required
            >
              <option value="">Pilih Tim</option>
              {teams.map((team) => (
                <option key={team.id} value={team.id}>
                  {team.name}
                </option>
              ))}
            </select>
          </div>

          <div className="mb-6">
            <label className="caption-uppercase block mb-2">
              Peran <span className="text-error">*</span>
            </label>
            <select
              value={formData.role}
              onChange={(e) => setFormData({ ...formData, role: e.target.value })}
              className="input-field"
              required
            >
              <option value="employee">Employee</option>
              <option value="manager">Manager</option>
            </select>
          </div>

          <Input
            label="Jatah Cuti Tahunan (hari)"
            type="number"
            value={formData.leave_quota_days}
            onChange={(e) => setFormData({ ...formData, leave_quota_days: e.target.value })}
            required
            min="0"
            max="365"
            helperText="Jumlah hari cuti yang dapat diambil karyawan dalam setahun"
          />

          <Input
            label={editingUser ? 'Kata Sandi (kosongkan jika tidak diubah)' : 'Kata Sandi'}
            type="password"
            value={formData.password}
            onChange={(e) => setFormData({ ...formData, password: e.target.value })}
            required={!editingUser}
          />

          <Input
            label="Konfirmasi Kata Sandi"
            type="password"
            value={formData.password_confirmation}
            onChange={(e) => setFormData({ ...formData, password_confirmation: e.target.value })}
            required={!editingUser || formData.password !== ''}
          />

          <div className="flex flex-col-reverse gap-3 sm:flex-row sm:justify-end sm:gap-4 mt-6">
            <Button
              type="button"
              variant="secondary"
              onClick={handleCloseModal}
            >
              Batal
            </Button>
            <Button type="submit" disabled={submitting}>
              {submitting ? 'Menyimpan...' : editingUser ? 'Perbarui Pengguna' : 'Buat Pengguna'}
            </Button>
          </div>
        </form>
      </Modal>
    </MainLayout>
  );
};
