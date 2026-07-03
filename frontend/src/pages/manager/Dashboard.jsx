import { useState, useEffect } from 'react';
import { MainLayout } from '../../components/layout/MainLayout';
import { Card } from '../../components/common/Card';
import { Loading } from '../../components/common/Loading';
import { getManagerDashboard } from '../../api/manager.api';
import { formatTime, formatHours, getTodayDate } from '../../utils/dateHelpers';
import { usePageTitle } from '../../hooks/usePageTitle';
import { Users, UserCheck, Calendar, TrendingUp, Clock } from 'lucide-react';
import toast from 'react-hot-toast';

export const ManagerDashboard = () => {
  usePageTitle('Dashboard');
  
  const [loading, setLoading] = useState(true);
  const [dashboard, setDashboard] = useState(null);
  const [selectedDate, setSelectedDate] = useState(getTodayDate());

  useEffect(() => {
    fetchDashboard();
  }, [selectedDate]);

  const fetchDashboard = async () => {
    try {
      setLoading(true);
      const response = await getManagerDashboard(selectedDate);
      
      if (response.success) {
        setDashboard(response.data);
      }
    } catch (error) {
      console.error('Error fetching dashboard:', error);
      toast.error('Gagal mengambil data dashboard');
    } finally {
      setLoading(false);
    }
  };

  if (loading) {
    return (
      <MainLayout>
        <Loading />
      </MainLayout>
    );
  }

  const summary = dashboard?.summary || {};
  const employees = dashboard?.employees || [];

  const getStatusColor = (status) => {
    switch (status) {
      case 'checked_in':
        return 'badge-success';
      case 'checked_out':
        return 'badge-muted';
      case 'on_leave':
        return 'badge-info';
      default:
        return 'badge-muted';
    }
  };

  return (
    <MainLayout>
      <div className="space-y-6 sm:space-y-8">
        {/* Header */}
        <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
          <div>
            <h1 className="text-display-md sm:text-display-lg">Dashboard Manager</h1>
            <p className="mt-2 font-serif text-body">Ringkasan absensi tim</p>
          </div>
          <div>
            <input
              type="date"
              value={selectedDate}
              onChange={(e) => setSelectedDate(e.target.value)}
              className="input-field"
            />
          </div>
        </div>

        {/* Summary Cards */}
        <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 sm:gap-6">
          <Card>
            <div className="flex items-center justify-between">
              <div>
                <p className="caption-uppercase mb-1">Total Karyawan</p>
                <p className="font-display text-display-md text-ink">{summary.total_employees || 0}</p>
              </div>
              <div className="w-10 h-10 rounded-full border border-hairline-strong flex items-center justify-center">
                <Users size={24} className="text-muted" />
              </div>
            </div>
          </Card>

          <Card>
            <div className="flex items-center justify-between">
              <div>
                <p className="caption-uppercase mb-1">Sedang Check In</p>
                <p className="font-display text-display-md text-ink">{summary.checked_in_now || 0}</p>
              </div>
              <div className="w-10 h-10 rounded-full border border-hairline-strong flex items-center justify-center">
                <UserCheck size={24} className="text-muted" />
              </div>
            </div>
          </Card>

          <Card>
            <div className="flex items-center justify-between">
              <div>
                <p className="caption-uppercase mb-1">Sedang Cuti</p>
                <p className="font-display text-display-md text-ink">{summary.on_leave || 0}</p>
              </div>
              <div className="w-10 h-10 rounded-full border border-hairline-strong flex items-center justify-center">
                <Calendar size={24} className="text-muted" />
              </div>
            </div>
          </Card>

          <Card>
            <div className="flex items-center justify-between">
              <div>
                <p className="caption-uppercase mb-1">Rata-rata Jam Harian</p>
                <p className="font-display text-display-md text-ink">
                  {(summary.average_daily_hours || 0).toFixed(1)}j
                </p>
              </div>
              <div className="w-10 h-10 rounded-full border border-hairline-strong flex items-center justify-center">
                <TrendingUp size={24} className="text-muted" />
              </div>
            </div>
          </Card>
        </div>

        {/* Employees List */}
        <Card title="Status Karyawan">
          {employees.length === 0 ? (
            <div className="caption-uppercase text-center py-16">
              Tidak ada data karyawan tersedia
            </div>
          ) : (
            <div className="overflow-x-auto">
              <table className="w-full">
                <thead>
                  <tr className="border-b border-hairline">
                    <th className="caption-uppercase text-left px-4 py-3">
                      Karyawan
                    </th>
                    <th className="caption-uppercase text-left px-4 py-3">
                      Status
                    </th>
                    <th className="caption-uppercase text-left px-4 py-3">
                      Hari Ini
                    </th>
                    <th className="caption-uppercase text-left px-4 py-3">
                      Minggu Ini
                    </th>
                    <th className="caption-uppercase text-left px-4 py-3">
                      Bulan Ini
                    </th>
                  </tr>
                </thead>
                <tbody>
                  {employees.map((employee) => (
                    <tr key={employee.id} className="border-b border-hairline last:border-0 hover:bg-surface-soft transition-colors">
                      <td className="px-4 py-3 whitespace-nowrap">
                        <div>
                          <p className="font-serif text-body-strong">{employee.name}</p>
                          <p className="font-serif text-sm text-muted">{employee.email}</p>
                        </div>
                      </td>
                      <td className="px-4 py-3 whitespace-nowrap">
                        <span className={`badge ${getStatusColor(employee.status)}`}>
                          {employee.status === 'checked_in' ? 'Check In' : employee.status === 'checked_out' ? 'Check Out' : 'Sedang Cuti'}
                        </span>
                        {employee.status === 'checked_in' && employee.current_session && (
                          <p className="font-mono text-xs text-muted mt-1">
                            Sejak {formatTime(employee.current_session.check_in)}
                          </p>
                        )}
                        {employee.status === 'on_leave' && employee.leave && (
                          <p className="font-serif text-xs text-muted mt-1">
                            {employee.leave.reason}
                          </p>
                        )}
                      </td>
                      <td className="px-4 py-3 whitespace-nowrap">
                        <div className="flex items-center space-x-1">
                          <Clock size={14} className="text-muted" />
                          <span className="font-mono text-sm text-body">
                            {formatHours(employee.today_total_hours || 0)}
                          </span>
                        </div>
                      </td>
                      <td className="px-4 py-3 whitespace-nowrap">
                        <span className="font-mono text-sm text-body">
                          {formatHours(employee.week_total_hours || 0)}
                        </span>
                      </td>
                      <td className="px-4 py-3 whitespace-nowrap">
                        <span className="font-mono text-sm text-body">
                          {formatHours(employee.month_total_hours || 0)}
                        </span>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </Card>
      </div>
    </MainLayout>
  );
};
