import { useState, useEffect } from 'react';
import { MainLayout } from '../../components/layout/MainLayout';
import { Card } from '../../components/common/Card';
import { Button } from '../../components/common/Button';
import { Loading } from '../../components/common/Loading';
import { Pagination } from '../../components/common/Pagination';
import { getActivityLogs } from '../../api/manager.api';
import { getAllUsers } from '../../api/manager.api';
import { formatDateTime } from '../../utils/dateHelpers';
import { usePageTitle } from '../../hooks/usePageTitle';
import { Activity, Filter } from 'lucide-react';
import toast from 'react-hot-toast';

export const ActivityLogs = () => {
  usePageTitle('Log Aktivitas');
  const [loading, setLoading] = useState(true);
  const [logs, setLogs] = useState([]);
  const [users, setUsers] = useState([]);
  const [filters, setFilters] = useState({
    user_id: '',
    action: '',
    start_date: '',
    end_date: '',
  });
  const [pagination, setPagination] = useState({
    current_page: 1,
    last_page: 1,
    per_page: 10,
    total: 0,
    from: 0,
    to: 0,
  });

  useEffect(() => {
    fetchUsers();
    fetchLogs(1, 10);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  const fetchUsers = async () => {
    try {
      const response = await getAllUsers(1, 1000); // Get all users for filter dropdown
      if (response.success) {
        setUsers(response.data);
      }
    } catch (error) {
      console.error('Error fetching users:', error);
    }
  };

  const fetchLogs = async (page = 1, perPage = 10) => {
    try {
      setLoading(true);
      // Clean filters - remove empty strings
      const cleanFilters = {};
      if (filters.user_id) cleanFilters.user_id = filters.user_id;
      if (filters.action) cleanFilters.action = filters.action;
      if (filters.start_date) cleanFilters.start_date = filters.start_date;
      if (filters.end_date) cleanFilters.end_date = filters.end_date;
      
      const response = await getActivityLogs(cleanFilters, page, perPage);
      
      if (response.success) {
        setLogs(response.data.logs || []);
        if (response.pagination) {
          setPagination(response.pagination);
        }
      } else {
        toast.error(response.message || 'Gagal mengambil log aktivitas');
      }
    } catch (error) {
      console.error('Error fetching activity logs:', error);
      const errorMessage = error.response?.data?.message || 'Gagal mengambil log aktivitas';
      toast.error(errorMessage);
    } finally {
      setLoading(false);
    }
  };

  const handlePageChange = (page) => {
    fetchLogs(page, pagination.per_page);
  };

  const handlePerPageChange = (perPage) => {
    fetchLogs(1, perPage);
  };

  const handleFilter = () => {
    fetchLogs();
  };

  const handleClearFilters = () => {
    const emptyFilters = {
      user_id: '',
      action: '',
      start_date: '',
      end_date: '',
    };
    setFilters(emptyFilters);
    // Reset filters and fetch logs with empty filters
    setTimeout(() => {
      // Use empty filters directly
      getActivityLogs({}, 1, pagination.per_page)
        .then((response) => {
          if (response.success) {
            setLogs(response.data.logs || []);
            if (response.pagination) {
              setPagination(response.pagination);
            }
          }
        })
        .catch((error) => {
          console.error('Error fetching activity logs:', error);
          toast.error('Gagal mengambil log aktivitas');
        });
    }, 100);
  };

  const getActionLabel = (action) => {
    const labels = {
      login: 'Login',
      logout: 'Logout',
      check_in: 'Check In',
      check_out: 'Check Out',
      task_created: 'Tugas Dibuat',
      task_updated: 'Tugas Diperbarui',
      attendance_edited: 'Absensi Diedit',
      attendance_deleted: 'Absensi Dihapus',
      user_created: 'Pengguna Dibuat',
      user_updated: 'Pengguna Diperbarui',
      user_deleted: 'Pengguna Dihapus',
      leave_requested: 'Cuti Diajukan',
      leave_approved: 'Cuti Disetujui',
      leave_rejected: 'Cuti Ditolak',
      holiday_created: 'Hari Libur Dibuat',
      holiday_updated: 'Hari Libur Diperbarui',
      holiday_deleted: 'Hari Libur Dihapus',
      auto_checkout: 'Auto Check Out',
    };
    return labels[action] || action;
  };

  const getActionBadge = (action) => {
    const badges = {
      login: 'badge-info',
      logout: 'badge-info',
      check_in: 'badge-success',
      check_out: 'badge-success',
      task_created: 'badge-info',
      task_updated: 'badge-info',
      attendance_edited: 'badge-warning',
      attendance_deleted: 'badge-danger',
      user_created: 'badge-success',
      user_updated: 'badge-warning',
      user_deleted: 'badge-danger',
      leave_requested: 'badge-info',
      leave_approved: 'badge-success',
      leave_rejected: 'badge-danger',
      holiday_created: 'badge-success',
      holiday_updated: 'badge-warning',
      holiday_deleted: 'badge-danger',
      auto_checkout: 'badge-warning',
    };
    return badges[action] || 'badge-info';
  };

  const actionTypes = [
    'check_in',
    'check_out',
    'task_created',
    'task_updated',
    'attendance_edited',
    'attendance_deleted',
    'user_created',
    'user_updated',
    'user_deleted',
    'leave_requested',
    'leave_approved',
    'leave_rejected',
    'holiday_created',
    'holiday_updated',
    'holiday_deleted',
    'auto_checkout',
    'login',
    'logout',
  ];

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
        <div>
          <h1 className="text-display-md sm:text-display-lg">Log Aktivitas</h1>
          <p className="mt-2 font-serif text-body">Pantau semua aktivitas sistem dan aksi pengguna</p>
        </div>

        {/* Filters */}
        <Card title="Filter">
          <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
            <div>
              <label className="caption-uppercase block mb-2">
                Pengguna
              </label>
              <select
                value={filters.user_id}
                onChange={(e) => setFilters({ ...filters, user_id: e.target.value })}
                className="input-field"
              >
                <option value="">Semua Pengguna</option>
                {users.map((user) => (
                  <option key={user.id} value={user.id}>
                    {user.name}
                  </option>
                ))}
              </select>
            </div>

            <div>
              <label className="caption-uppercase block mb-2">
                Jenis Aksi
              </label>
              <select
                value={filters.action}
                onChange={(e) => setFilters({ ...filters, action: e.target.value })}
                className="input-field"
              >
                <option value="">Semua Aksi</option>
                {actionTypes.map((action) => (
                  <option key={action} value={action}>
                    {getActionLabel(action)}
                  </option>
                ))}
              </select>
            </div>

            <div>
              <label className="caption-uppercase block mb-2">
                Tanggal Mulai
              </label>
              <input
                type="date"
                value={filters.start_date}
                onChange={(e) => setFilters({ ...filters, start_date: e.target.value })}
                className="input-field"
              />
            </div>

            <div>
              <label className="caption-uppercase block mb-2">
                Tanggal Akhir
              </label>
              <input
                type="date"
                value={filters.end_date}
                onChange={(e) => setFilters({ ...filters, end_date: e.target.value })}
                className="input-field"
              />
            </div>
          </div>

          <div className="flex flex-wrap items-center gap-3 mt-6">
            <Button onClick={handleFilter}>
              <Filter size={18} />
              <span>Terapkan Filter</span>
            </Button>
            <Button onClick={handleClearFilters} variant="secondary">
              Hapus Filter
            </Button>
          </div>
        </Card>

        {/* Activity Logs */}
        <Card>
          {logs.length === 0 ? (
            <div className="text-center py-16">
              <Activity size={48} className="mx-auto text-muted mb-4" />
              <p className="caption-uppercase">Tidak ada log aktivitas ditemukan</p>
            </div>
          ) : (
            <div className="space-y-3">
              {logs.map((log) => (
                <div
                  key={log.id}
                  className="border border-hairline rounded-none p-4 hover:bg-surface-soft transition-colors"
                >
                  <div className="flex items-start justify-between">
                    <div className="flex-1">
                      <div className="flex flex-wrap items-center gap-x-3 gap-y-1 mb-2">
                        <span className={`badge ${getActionBadge(log.action)}`}>
                          {getActionLabel(log.action)}
                        </span>
                        {log.user && (
                          <>
                            <p className="font-serif text-sm text-body-strong">
                              {log.user.name}
                            </p>
                            <p className="font-mono text-xs text-muted">
                              {log.user.email}
                            </p>
                          </>
                        )}
                      </div>
                      
                      <p className="font-serif text-sm text-body mb-2">
                        {log.description || '-'}
                      </p>

                      <div className="flex flex-wrap items-center gap-x-4 gap-y-1 font-mono text-xs text-muted">
                        <span>{formatDateTime(log.created_at)}</span>
                        {log.ip_address && <span>IP: {log.ip_address}</span>}
                      </div>
                    </div>
                  </div>
                </div>
              ))}
            </div>
          )}
          
          {logs.length > 0 && (
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
    </MainLayout>
  );
};
