import { useState, useEffect } from 'react';
import { MainLayout } from '../../components/layout/MainLayout';
import { Card } from '../../components/common/Card';
import { Button } from '../../components/common/Button';
import { Loading } from '../../components/common/Loading';
import { Modal } from '../../components/common/Modal';
import { getMonthlyAttendanceReport } from '../../api/manager.api';
import { formatDate, formatTime, formatHours, getMonthStart, getMonthEnd } from '../../utils/dateHelpers';
import { usePageTitle } from '../../hooks/usePageTitle';
import { Calendar, Clock, User, TrendingUp, CheckCircle, XCircle, ChevronDown, ChevronUp, AlertTriangle } from 'lucide-react';
import toast from 'react-hot-toast';

export const MonthlyAttendanceReport = () => {
  usePageTitle('Laporan Bulanan');
  const [loading, setLoading] = useState(true);
  const [report, setReport] = useState(null);
  const [startDate, setStartDate] = useState(getMonthStart());
  const [endDate, setEndDate] = useState(getMonthEnd());
  const [selectedEmployee, setSelectedEmployee] = useState(null);
  const [showDetailModal, setShowDetailModal] = useState(false);
  const [expandedTasks, setExpandedTasks] = useState({});

  useEffect(() => {
    fetchReport();
  }, []);

  const fetchReport = async () => {
    try {
      setLoading(true);
      const response = await getMonthlyAttendanceReport(startDate, endDate);
      
      if (response.success) {
        setReport(response.data);
      }
    } catch (error) {
      console.error('Error fetching monthly attendance report:', error);
      toast.error('Gagal mengambil laporan absensi bulanan');
    } finally {
      setLoading(false);
    }
  };

  const handleFilter = () => {
    fetchReport();
  };

  const handleEmployeeClick = (employeeData) => {
    setSelectedEmployee(employeeData);
    setShowDetailModal(true);
    setExpandedTasks({});
  };

  const handleCloseModal = () => {
    setShowDetailModal(false);
    setSelectedEmployee(null);
    setExpandedTasks({});
  };

  const toggleTaskExpand = (dateIndex, sessionIndex) => {
    const key = `${dateIndex}-${sessionIndex}`;
    setExpandedTasks(prev => ({
      ...prev,
      [key]: !prev[key]
    }));
  };

  const isTaskExpanded = (dateIndex, sessionIndex) => {
    const key = `${dateIndex}-${sessionIndex}`;
    return expandedTasks[key] || false;
  };

  if (loading) {
    return (
      <MainLayout>
        <Loading />
      </MainLayout>
    );
  }

  const employees = report?.employees || [];
  const requiredHours = report?.required_hours || 7;

  return (
    <MainLayout>
      <div className="space-y-6 sm:space-y-8">
        {/* Header */}
        <div>
          <h1 className="text-display-md sm:text-display-lg">Laporan Absensi Bulanan</h1>
          <p className="mt-2 font-serif text-body">Lihat ringkasan jam kerja semua karyawan dalam rentang tanggal</p>
        </div>

        {/* Date Range Filter */}
        <Card>
          <div className="flex flex-col gap-4 sm:flex-row sm:items-end sm:gap-4">
            <div className="w-full sm:flex-1">
              <label className="caption-uppercase block mb-2">
                Tanggal Mulai
              </label>
              <input
                type="date"
                value={startDate}
                onChange={(e) => setStartDate(e.target.value)}
                className="input-field w-full"
              />
            </div>
            <div className="w-full sm:flex-1">
              <label className="caption-uppercase block mb-2">
                Tanggal Akhir
              </label>
              <input
                type="date"
                value={endDate}
                onChange={(e) => setEndDate(e.target.value)}
                className="input-field w-full"
              />
            </div>
            <div className="w-full sm:w-auto">
              <Button
                className="w-full sm:w-auto"
                onClick={handleFilter}
              >
                Terapkan Filter
              </Button>
            </div>
          </div>
        </Card>

        {/* Summary Cards */}
        {report && (
          <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 sm:gap-6">
            <Card>
              <div className="flex items-center justify-between gap-4">
                <div>
                  <p className="caption-uppercase mb-1">Periode</p>
                  <p className="font-mono text-sm text-ink">
                    {formatDate(report.start_date)} - {formatDate(report.end_date)}
                  </p>
                </div>
                <div className="w-10 h-10 rounded-xl border-2 border-ink flex items-center justify-center flex-shrink-0">
                  <Calendar size={24} className="text-muted" />
                </div>
              </div>
            </Card>

            <Card>
              <div className="flex items-center justify-between gap-4">
                <div>
                  <p className="caption-uppercase mb-1">Hari Kerja</p>
                  <p className="font-display text-display-md text-ink">{report.working_days || 0} hari</p>
                </div>
                <div className="w-10 h-10 rounded-xl border-2 border-ink flex items-center justify-center flex-shrink-0">
                  <Calendar size={24} className="text-muted" />
                </div>
              </div>
            </Card>

            <Card>
              <div className="flex items-center justify-between gap-4">
                <div>
                  <p className="caption-uppercase mb-1">Jam Kerja Semestinya</p>
                  <p className="font-display text-display-md text-ink">{report.expected_total_hours || 0} jam</p>
                  <p className="caption-uppercase mt-1">{report.working_days || 0} hari × 8 jam</p>
                </div>
                <div className="w-10 h-10 rounded-xl border-2 border-ink flex items-center justify-center flex-shrink-0">
                  <Clock size={24} className="text-muted" />
                </div>
              </div>
            </Card>

            <Card>
              <div className="flex items-center justify-between gap-4">
                <div>
                  <p className="caption-uppercase mb-1">Total Karyawan</p>
                  <p className="font-display text-display-md text-ink">{employees.length}</p>
                </div>
                <div className="w-10 h-10 rounded-xl border-2 border-ink flex items-center justify-center flex-shrink-0">
                  <User size={24} className="text-muted" />
                </div>
              </div>
            </Card>
          </div>
        )}

        {/* Employees Table */}
        <Card title="Employees Summary">
          {employees.length === 0 ? (
            <div className="caption-uppercase text-center py-16">
              No attendance records found for the selected period
            </div>
          ) : (
            <div className="overflow-x-auto">
              <table className="w-full">
                <thead>
                  <tr className="bg-surface-soft border-b border-hairline">
                    <th className="caption-uppercase text-left px-4 py-3">
                      Karyawan
                    </th>
                    <th className="caption-uppercase text-left px-4 py-3">
                      Total Jam
                    </th>
                    <th className="caption-uppercase text-left px-4 py-3">
                      Jam Lembur
                    </th>
                    <th className="caption-uppercase text-left px-4 py-3">
                      Kurang Jam
                    </th>
                    <th className="caption-uppercase text-left px-4 py-3">
                      Hari Masuk
                    </th>
                    <th className="caption-uppercase text-left px-4 py-3">
                      Hari Cuti
                    </th>
                  </tr>
                </thead>
                <tbody>
                  {employees.map((employeeData) => {
                    const { employee, total_hours, total_overtime_hours, total_deficit_hours, total_days_worked, total_leave_days } = employeeData;

                    return (
                      <tr key={employee.id} className="border-b border-hairline last:border-0 hover:bg-surface-soft transition-colors">
                        <td className="px-4 py-3 whitespace-nowrap">
                          <div className="flex items-center">
                            <div className="flex-shrink-0 h-10 w-10 rounded-xl border-2 border-ink flex items-center justify-center">
                              <User className="text-muted" size={20} />
                            </div>
                            <div className="ml-4">
                              <button
                                onClick={() => handleEmployeeClick(employeeData)}
                                className="font-serif text-sm text-ink hover:underline underline-offset-4"
                              >
                                {employee.name}
                              </button>
                              <div className="font-serif text-sm text-muted">
                                {employee.email}
                              </div>
                            </div>
                          </div>
                        </td>
                        <td className="px-4 py-3 whitespace-nowrap font-mono text-sm text-ink">
                          <div className="flex items-center">
                            <Clock size={16} className="text-muted mr-2" />
                            {formatHours(total_hours)}
                          </div>
                        </td>
                        <td className="px-4 py-3 whitespace-nowrap font-mono text-sm">
                          {total_overtime_hours > 0 ? (
                            <span className="inline-flex items-center text-warning">
                              <TrendingUp size={14} className="mr-1" />
                              +{formatHours(total_overtime_hours)}
                            </span>
                          ) : (
                            <span className="text-muted-soft">-</span>
                          )}
                        </td>
                        <td className="px-4 py-3 whitespace-nowrap font-mono text-sm">
                          {total_deficit_hours > 0 ? (
                            <span className="inline-flex items-center text-error">
                              <AlertTriangle size={14} className="mr-1" />
                              -{formatHours(total_deficit_hours)}
                            </span>
                          ) : (
                            <span className="text-muted-soft">-</span>
                          )}
                        </td>
                        <td className="px-4 py-3 whitespace-nowrap font-mono text-sm text-ink">
                          {total_days_worked} hari
                        </td>
                        <td className="px-4 py-3 whitespace-nowrap font-mono text-sm">
                          {total_leave_days > 0 ? (
                            <span className="badge badge-info">
                              {total_leave_days} hari
                            </span>
                          ) : (
                            <span className="text-muted-soft">-</span>
                          )}
                        </td>
                      </tr>
                    );
                  })}
                </tbody>
              </table>
            </div>
          )}
        </Card>

        {/* Employee Detail Modal */}
        <Modal
          isOpen={showDetailModal}
          onClose={handleCloseModal}
          title={selectedEmployee ? `Detail Absensi Harian - ${selectedEmployee.employee.name}` : ''}
          size="xl"
        >
          {selectedEmployee && (
            <div className="space-y-4">
              {/* Summary */}
              <div className="rounded-xl border-2 border-ink bg-surface-soft p-4 mb-4">
                <div className="grid grid-cols-2 md:grid-cols-5 gap-4">
                  <div>
                    <p className="caption-uppercase mb-1">Total Jam</p>
                    <p className="font-mono text-title-sm text-ink">
                      {formatHours(selectedEmployee.total_hours)}
                    </p>
                  </div>
                  <div>
                    <p className="caption-uppercase mb-1">Jam Lembur</p>
                    <p className="font-mono text-title-sm text-warning">
                      {selectedEmployee.total_overtime_hours > 0
                        ? `+${formatHours(selectedEmployee.total_overtime_hours)}`
                        : '-'}
                    </p>
                  </div>
                  <div>
                    <p className="caption-uppercase mb-1">Kurang Jam</p>
                    <p className="font-mono text-title-sm text-error">
                      {selectedEmployee.total_deficit_hours > 0
                        ? `-${formatHours(selectedEmployee.total_deficit_hours)}`
                        : '-'}
                    </p>
                  </div>
                  <div>
                    <p className="caption-uppercase mb-1">Hari Masuk</p>
                    <p className="font-mono text-title-sm text-ink">
                      {selectedEmployee.total_days_worked} hari
                    </p>
                  </div>
                  <div>
                    <p className="caption-uppercase mb-1">Hari Cuti</p>
                    <p className="font-mono text-title-sm text-ink">
                      {selectedEmployee.total_leave_days > 0
                        ? `${selectedEmployee.total_leave_days} hari`
                        : '-'}
                    </p>
                  </div>
                </div>
              </div>

              {/* Daily Details */}
              <div className="space-y-4 max-h-96 overflow-y-auto">
                {selectedEmployee.daily_details && selectedEmployee.daily_details.length > 0 ? (
                  selectedEmployee.daily_details.map((dailyDetail, dateIndex) => (
                    <div key={dailyDetail.date} className="rounded-xl border-2 border-ink bg-white p-4">
                      {/* Date Header */}
                      <div className="flex items-center justify-between mb-3">
                        <div>
                          <p className="font-mono text-sm text-ink">{formatDate(dailyDetail.date)}</p>
                          <p className="font-mono text-sm text-muted">
                            Total: {formatHours(dailyDetail.daily_total_hours)}
                            {dailyDetail.overtime_hours > 0 && (
                              <span className="text-warning ml-2">
                                (+{formatHours(dailyDetail.overtime_hours)} lembur)
                              </span>
                            )}
                          </p>
                        </div>
                        <span className={`badge ${
                          dailyDetail.status === 'complete'
                            ? 'badge-success'
                            : dailyDetail.status === 'incomplete'
                            ? 'badge-warning'
                            : dailyDetail.status === 'on_leave'
                            ? 'badge-info'
                            : 'badge-info'
                        }`}>
                          {dailyDetail.status === 'complete'
                            ? 'Lengkap'
                            : dailyDetail.status === 'incomplete'
                            ? 'Tidak Lengkap'
                            : dailyDetail.status === 'on_leave'
                            ? 'Cuti'
                            : 'Lembur'}
                        </span>
                      </div>

                      {/* Sessions */}
                      {dailyDetail.sessions && dailyDetail.sessions.length > 0 && (
                        <div className="space-y-2 mt-3">
                          {dailyDetail.sessions.map((session, sessionIndex) => {
                            const isTaskExp = isTaskExpanded(dateIndex, sessionIndex);
                            const hasCompletedTasks = session.tasks_completed > 0;
                            const hasIncompleteTasks = session.tasks_incomplete > 0;

                            return (
                              <div key={sessionIndex} className="rounded-xl border-2 border-ink bg-surface-soft p-3">
                                <div className="flex items-center justify-between mb-2">
                                  <div className="flex items-center space-x-3">
                                    <p className="font-mono text-sm text-body">
                                      Sesi {session.session_number}
                                    </p>
                                    <p className="font-mono text-sm text-muted">
                                      {formatTime(session.check_in)} - {session.check_out ? formatTime(session.check_out) : 'Aktif'}
                                    </p>
                                  </div>
                                  <p className="font-mono text-sm text-muted">
                                    {formatHours(session.total_hours)}
                                  </p>
                                </div>

                                {/* Task Summary - Clickable */}
                                <div className="flex items-center space-x-4 font-mono text-xs">
                                  {hasCompletedTasks && (
                                    <button
                                      onClick={() => toggleTaskExpand(dateIndex, sessionIndex)}
                                      className="flex items-center space-x-1 text-success hover:opacity-70 px-2 py-1 rounded-xl transition-opacity duration-200"
                                    >
                                      <CheckCircle size={14} />
                                      <span>{session.tasks_completed} selesai</span>
                                      {isTaskExp ? <ChevronUp size={14} /> : <ChevronDown size={14} />}
                                    </button>
                                  )}
                                  {hasIncompleteTasks && (
                                    <button
                                      onClick={() => toggleTaskExpand(dateIndex, sessionIndex)}
                                      className="flex items-center space-x-1 text-error hover:opacity-70 px-2 py-1 rounded-xl transition-opacity duration-200"
                                    >
                                      <XCircle size={14} />
                                      <span>{session.tasks_incomplete} belum selesai</span>
                                      {isTaskExp ? <ChevronUp size={14} /> : <ChevronDown size={14} />}
                                    </button>
                                  )}
                                </div>

                                {/* Expanded Task Details */}
                                {isTaskExp && session.tasks && session.tasks.length > 0 && (
                                  <div className="mt-3 pt-3 border-t border-hairline">
                                    <p className="caption-uppercase mb-2">Detail Tugas:</p>
                                    <div className="space-y-2">
                                      {session.tasks.map((task, taskIndex) => (
                                        <div
                                          key={taskIndex}
                                          className={`p-2 rounded-xl text-xs ${
                                            task.is_completed
                                              ? 'border-2 border-ink'
                                              : 'border-2 border-error bg-error/5'
                                          }`}
                                        >
                                          <div className="flex items-start space-x-2">
                                            {task.is_completed ? (
                                              <CheckCircle size={14} className="text-success mt-0.5 flex-shrink-0" />
                                            ) : (
                                              <XCircle size={14} className="text-error mt-0.5 flex-shrink-0" />
                                            )}
                                            <div className="flex-1">
                                              <p className={`font-serif ${
                                                task.is_completed ? 'text-body' : 'text-error'
                                              }`}>
                                                {task.title}
                                              </p>
                                              {!task.is_completed && task.blocker_reason && (
                                                <p className="font-serif text-error mt-1 text-xs">
                                                  <span className="font-mono uppercase">Blocker:</span> {task.blocker_reason}
                                                </p>
                                              )}
                                            </div>
                                          </div>
                                        </div>
                                      ))}
                                    </div>
                                  </div>
                                )}
                              </div>
                            );
                          })}
                        </div>
                      )}
                    </div>
                  ))
                ) : (
                  <div className="caption-uppercase text-center py-16">
                    Tidak ada catatan absensi untuk karyawan ini dalam periode yang dipilih
                  </div>
                )}
              </div>
            </div>
          )}
        </Modal>
      </div>
    </MainLayout>
  );
};
