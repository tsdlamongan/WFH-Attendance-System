import { useState, useEffect } from 'react';
import { MainLayout } from '../../components/layout/MainLayout';
import { Card } from '../../components/common/Card';
import { Button } from '../../components/common/Button';
import { Input } from '../../components/common/Input';
import { Loading } from '../../components/common/Loading';
import { Modal } from '../../components/common/Modal';
import { Pagination } from '../../components/common/Pagination';
import { getAllUsers, createUser, updateUser, deleteUser, toggleUserDisabled } from '../../api/manager.api';
import { formatDate } from '../../utils/dateHelpers';
import { usePageTitle } from '../../hooks/usePageTitle';
import { Users, Plus, Edit, Trash2, Shield, User, Ban, CheckCircle } from 'lucide-react';
import toast from 'react-hot-toast';

export const UserManagement = () => {
  usePageTitle('Pengguna');
  const [loading, setLoading] = useState(true);
  const [users, setUsers] = useState([]);
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
    leave_quota_days: 12,
  });

  useEffect(() => {
    fetchUsers(1, 10);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

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
        leave_quota_days: 12,
      });
    }
    setShowModal(true);
  };

  const handleCloseModal = () => {
    setShowModal(false);
    setEditingUser(null);
    setFormData({
      name: '',
      email: '',
      password: '',
      password_confirmation: '',
      role: 'employee',
      leave_quota_days: 12,
    });
  };

  const handleSubmit = async (e) => {
    e.preventDefault();
    
    // Validation
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
        leave_quota_days: parseInt(formData.leave_quota_days) || 12,
      };

      // Only include password if it's provided
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
            <h1 className="text-display-md sm:text-display-lg">Pengguna</h1>
            <p className="mt-2 font-serif text-body">Kelola akun karyawan dan manager</p>
          </div>
          <Button
            onClick={() => handleOpenModal()}
            className="self-start sm:self-auto"
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
            <div className="rounded-xl border-2 border-ink overflow-hidden">
              <div className="overflow-x-auto">
                <table className="w-full">
                  <thead>
                    <tr className="bg-surface-soft border-b-2 border-ink">
                    <th className="caption-uppercase text-left px-4 py-3">
                      Pengguna
                    </th>
                    <th className="caption-uppercase text-left px-4 py-3">
                      Peran
                    </th>
                    <th className="caption-uppercase text-left px-4 py-3">
                      Jatah Cuti
                    </th>
                    <th className="caption-uppercase text-left px-4 py-3">
                      Sisa Cuti
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
                          <div className="w-10 h-10 rounded-xl border-2 border-ink flex items-center justify-center">
                            {user.role === 'manager' ? (
                              <Shield size={20} className="text-muted" />
                            ) : (
                              <User size={20} className="text-muted" />
                            )}
                          </div>
                          <div>
                            <p className="font-serif text-body-strong">{user.name}</p>
                            <p className="font-serif text-sm text-muted">{user.email}</p>
                          </div>
                        </div>
                      </td>
                      <td className="px-4 py-3 whitespace-nowrap">
                        <span className={`badge ${
                          user.role === 'manager' ? 'badge-info' : 'badge-success'
                        }`}>
                          {user.role === 'manager' ? 'Manager' : 'Karyawan'}
                        </span>
                      </td>
                      <td className="px-4 py-3 whitespace-nowrap">
                        <span className="font-mono text-sm text-body">
                          {user.leave_quota_days || 12} hari
                        </span>
                      </td>
                      <td className="px-4 py-3 whitespace-nowrap">
                        <span className={`font-mono text-sm ${
                          user.remaining_leave_days > 5 ? 'text-success' :
                          user.remaining_leave_days > 2 ? 'text-warning' :
                          'text-error'
                        }`}>
                          {user.remaining_leave_days || 0} hari
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
                        <button
                          onClick={() => handleOpenModal(user)}
                          className="p-2 text-muted hover:text-ink transition-colors mr-2"
                          title="Edit"
                        >
                          <Edit size={18} />
                        </button>
                        <button
                          onClick={() => handleToggleDisabled(user)}
                          className={`p-2 transition-colors mr-2 ${user.is_disabled ? 'text-success hover:text-ink' : 'text-warning hover:text-ink'}`}
                          title={user.is_disabled ? 'Aktifkan' : 'Nonaktifkan'}
                        >
                          {user.is_disabled ? <CheckCircle size={18} /> : <Ban size={18} />}
                        </button>
                        <button
                          onClick={() => handleDelete(user.id, user.name)}
                          className="p-2 text-muted hover:text-error transition-colors"
                          title="Hapus"
                        >
                          <Trash2 size={18} />
                        </button>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
              </div>
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
              Peran <span className="text-error">*</span>
            </label>
            <select
              value={formData.role}
              onChange={(e) => setFormData({ ...formData, role: e.target.value })}
              className="input-field"
              required
            >
              <option value="employee">Karyawan</option>
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

          <div className="flex flex-col-reverse sm:flex-row sm:justify-end gap-3 mt-6">
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
