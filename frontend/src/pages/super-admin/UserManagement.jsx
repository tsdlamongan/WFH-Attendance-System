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
      toast.error('Please select a team');
      return;
    }

    if (!editingUser && formData.password.length < 8) {
      toast.error('Password must be at least 8 characters');
      return;
    }

    if (!editingUser && formData.password !== formData.password_confirmation) {
      toast.error('Passwords do not match');
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
      return <Shield size={20} className="text-yellow-600" />;
    }
    if (role === 'manager') {
      return <Shield size={20} className="text-purple-600" />;
    }
    return <User size={20} className="text-blue-600" />;
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
      <div className="space-y-6">
        {/* Header */}
        <div className="flex items-center justify-between">
          <div>
            <h1 className="text-3xl font-bold text-gray-900">Manajemen Pengguna</h1>
            <p className="text-gray-600 mt-1">Kelola semua pengguna dalam sistem</p>
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
            <div className="text-center py-12">
              <Users size={48} className="mx-auto text-gray-400 mb-4" />
              <p className="text-gray-600">Tidak ada pengguna ditemukan</p>
            </div>
          ) : (
            <div className="overflow-x-auto">
              <table className="min-w-full divide-y divide-gray-200">
                <thead className="bg-gray-50">
                  <tr>
                    <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                      Pengguna
                    </th>
                    <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                      Tim
                    </th>
                    <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                      Peran
                    </th>
                    <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                      Jatah Cuti
                    </th>
                    <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                      Status
                    </th>
                    <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                      Dibuat Pada
                    </th>
                    <th className="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">
                      Aksi
                    </th>
                  </tr>
                </thead>
                <tbody className="bg-white divide-y divide-gray-200">
                  {users.map((user) => (
                    <tr key={user.id} className={`hover:bg-gray-50 ${user.is_disabled ? 'opacity-60' : ''}`}>
                      <td className="px-6 py-4 whitespace-nowrap">
                        <div className="flex items-center space-x-3">
                          <div className={`p-2 rounded-full ${
                            user.role === 'super_admin' ? 'bg-yellow-100' :
                            user.role === 'manager' ? 'bg-purple-100' : 'bg-blue-100'
                          }`}>
                            {getRoleIcon(user.role)}
                          </div>
                          <div>
                            <p className="font-medium text-gray-900">{user.name}</p>
                            <p className="text-sm text-gray-500">{user.email}</p>
                          </div>
                        </div>
                      </td>
                      <td className="px-6 py-4 whitespace-nowrap">
                        <div className="flex items-center text-sm text-gray-900">
                          <Building2 size={16} className="mr-1 text-gray-400" />
                          {getTeamName(user)}
                        </div>
                      </td>
                      <td className="px-6 py-4 whitespace-nowrap">
                        {getRoleBadge(user.role)}
                      </td>
                      <td className="px-6 py-4 whitespace-nowrap">
                        <span className="text-sm font-medium text-gray-900">
                          {user.leave_quota_days || 0} hari
                        </span>
                      </td>
                      <td className="px-6 py-4 whitespace-nowrap">
                        <span className={`badge ${user.is_disabled ? 'badge-error' : 'badge-success'}`}>
                          {user.is_disabled ? 'Dinonaktifkan' : 'Aktif'}
                        </span>
                      </td>
                      <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                        {formatDate(user.created_at)}
                      </td>
                      <td className="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                        <div className="flex justify-end space-x-2">
                          {user.role !== 'super_admin' && !user.is_disabled && (
                            <button
                              onClick={() => handleImpersonate(user)}
                              className="text-green-600 hover:text-green-900"
                              title="Impersonate"
                            >
                              <UserCheck size={18} />
                            </button>
                          )}
                          <button
                            onClick={() => handleOpenModal(user)}
                            className="text-primary-600 hover:text-primary-900"
                            title="Edit"
                          >
                            <Edit size={18} />
                          </button>
                          {user.role !== 'super_admin' && (
                            <button
                              onClick={() => handleToggleDisabled(user)}
                              className={user.is_disabled ? 'text-green-600 hover:text-green-900' : 'text-yellow-600 hover:text-yellow-900'}
                              title={user.is_disabled ? 'Aktifkan' : 'Nonaktifkan'}
                            >
                              {user.is_disabled ? <CheckCircle size={18} /> : <Ban size={18} />}
                            </button>
                          )}
                          {user.role !== 'super_admin' && (
                            <button
                              onClick={() => handleDelete(user.id, user.name)}
                              className="text-red-600 hover:text-red-900"
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

          <div className="mb-4">
            <label className="block text-sm font-medium text-gray-700 mb-2">
              Tim <span className="text-red-500">*</span>
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

          <div className="mb-4">
            <label className="block text-sm font-medium text-gray-700 mb-2">
              Peran <span className="text-red-500">*</span>
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

          <div className="flex justify-end space-x-3 mt-6">
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
