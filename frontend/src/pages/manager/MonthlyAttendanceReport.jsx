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
      <div className="space-y-6">
        {/* Header */}
        <div>
          <h1 className="text-3xl font-bold text-gray-900">Laporan Absensi Bulanan</h1>
          <p className="text-gray-600 mt-1">Lihat ringkasan jam kerja semua karyawan dalam rentang tanggal</p>
        </div>

        {/* Date Range Filter */}
        <Card>
          <div className="flex flex-col gap-4 sm:flex-row sm:items-end sm:gap-4">
            <div className="w-full sm:flex-1">
              <label className="block text-sm font-medium text-gray-700 mb-2">
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
              <label className="block text-sm font-medium text-gray-700 mb-2">
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
          <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
            <Card>
              <div className="flex items-center justify-between">
                <div>
                  <p className="text-sm text-gray-600 mb-1">Periode</p>
                  <p className="text-lg font-bold text-gray-900">
                    {formatDate(report.start_date)} - {formatDate(report.end_date)}
                  </p>
                </div>
                <div className="p-3 rounded-full bg-primary-100">
                  <Calendar size={24} className="text-primary-600" />
                </div>
              </div>
            </Card>

            <Card>
              <div className="flex items-center justify-between">
                <div>
                  <p className="text-sm text-gray-600 mb-1">Hari Kerja</p>
                  <p className="text-2xl font-bold text-primary-600">{report.working_days || 0} hari</p>
                </div>
                <div className="p-3 rounded-full bg-blue-100">
                  <Calendar size={24} className="text-blue-600" />
                </div>
              </div>
            </Card>

            <Card>
              <div className="flex items-center justify-between">
                <div>
                  <p className="text-sm text-gray-600 mb-1">Jam Kerja Semestinya</p>
                  <p className="text-2xl font-bold text-green-600">{report.expected_total_hours || 0} jam</p>
                  <p className="text-xs text-gray-500">{report.working_days || 0} hari × 8 jam</p>
                </div>
                <div className="p-3 rounded-full bg-green-100">
                  <Clock size={24} className="text-green-600" />
                </div>
              </div>
            </Card>

            <Card>
              <div className="flex items-center justify-between">
                <div>
                  <p className="text-sm text-gray-600 mb-1">Total Karyawan</p>
                  <p className="text-2xl font-bold text-purple-600">{employees.length}</p>
                </div>
                <div className="p-3 rounded-full bg-purple-100">
                  <User size={24} className="text-purple-600" />
                </div>
              </div>
            </Card>
          </div>
        )}

        {/* Employees Table */}
        <Card title="Employees Summary">
          {employees.length === 0 ? (
            <div className="text-center py-8 text-gray-500">
              No attendance records found for the selected period
            </div>
          ) : (
            <div className="overflow-x-auto">
              <table className="min-w-full divide-y divide-gray-200">
                <thead className="bg-gray-50">
                  <tr>
                    <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                      Karyawan
                    </th>
                    <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                      Total Jam
                    </th>
                    <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                      Jam Lembur
                    </th>
                    <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                      Kurang Jam
                    </th>
                    <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                      Hari Masuk
                    </th>
                  </tr>
                </thead>
                <tbody className="bg-white divide-y divide-gray-200">
                  {employees.map((employeeData) => {
                    const { employee, total_hours, total_overtime_hours, total_deficit_hours, total_days_worked } = employeeData;

                    return (
                      <tr key={employee.id} className="hover:bg-gray-50">
                        <td className="px-6 py-4 whitespace-nowrap">
                          <div className="flex items-center">
                            <div className="flex-shrink-0 h-10 w-10 rounded-full bg-primary-100 flex items-center justify-center">
                              <User className="text-primary-600" size={20} />
                            </div>
                            <div className="ml-4">
                              <button
                                onClick={() => handleEmployeeClick(employeeData)}
                                className="text-sm font-medium text-primary-600 hover:text-primary-900 hover:underline"
                              >
                                {employee.name}
                              </button>
                              <div className="text-sm text-gray-500">
                                {employee.email}
                              </div>
                            </div>
                          </div>
                        </td>
                        <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                          <div className="flex items-center">
                            <Clock size={16} className="text-gray-400 mr-2" />
                            {formatHours(total_hours)}
                          </div>
                        </td>
                        <td className="px-6 py-4 whitespace-nowrap text-sm">
                          {total_overtime_hours > 0 ? (
                            <span className="inline-flex items-center text-orange-600 font-medium">
                              <TrendingUp size={14} className="mr-1" />
                              +{formatHours(total_overtime_hours)}
                            </span>
                          ) : (
                            <span className="text-gray-400">-</span>
                          )}
                        </td>
                        <td className="px-6 py-4 whitespace-nowrap text-sm">
                          {total_deficit_hours > 0 ? (
                            <span className="inline-flex items-center text-red-600 font-medium">
                              <AlertTriangle size={14} className="mr-1" />
                              -{formatHours(total_deficit_hours)}
                            </span>
                          ) : (
                            <span className="text-gray-400">-</span>
                          )}
                        </td>
                        <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                          {total_days_worked} hari
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
              <div className="bg-gray-50 rounded-lg p-4 mb-4">
                <div className="grid grid-cols-2 md:grid-cols-4 gap-4">
                  <div>
                    <p className="text-sm text-gray-600">Total Jam</p>
                    <p className="text-lg font-bold text-gray-900">
                      {formatHours(selectedEmployee.total_hours)}
                    </p>
                  </div>
                  <div>
                    <p className="text-sm text-gray-600">Jam Lembur</p>
                    <p className="text-lg font-bold text-orange-600">
                      {selectedEmployee.total_overtime_hours > 0
                        ? `+${formatHours(selectedEmployee.total_overtime_hours)}`
                        : '-'}
                    </p>
                  </div>
                  <div>
                    <p className="text-sm text-gray-600">Kurang Jam</p>
                    <p className="text-lg font-bold text-red-600">
                      {selectedEmployee.total_deficit_hours > 0
                        ? `-${formatHours(selectedEmployee.total_deficit_hours)}`
                        : '-'}
                    </p>
                  </div>
                  <div>
                    <p className="text-sm text-gray-600">Hari Masuk</p>
                    <p className="text-lg font-bold text-gray-900">
                      {selectedEmployee.total_days_worked} hari
                    </p>
                  </div>
                </div>
              </div>

              {/* Daily Details */}
              <div className="space-y-4 max-h-96 overflow-y-auto">
                {selectedEmployee.daily_details && selectedEmployee.daily_details.length > 0 ? (
                  selectedEmployee.daily_details.map((dailyDetail, dateIndex) => (
                    <div key={dailyDetail.date} className="border border-gray-200 rounded-lg p-4">
                      {/* Date Header */}
                      <div className="flex items-center justify-between mb-3">
                        <div>
                          <p className="font-semibold text-gray-900">{formatDate(dailyDetail.date)}</p>
                          <p className="text-sm text-gray-600">
                            Total: {formatHours(dailyDetail.daily_total_hours)}
                            {dailyDetail.overtime_hours > 0 && (
                              <span className="text-orange-600 ml-2">
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
                            : 'badge-info'
                        }`}>
                          {dailyDetail.status === 'complete' ? 'Lengkap' : dailyDetail.status === 'incomplete' ? 'Tidak Lengkap' : 'Lembur'}
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
                              <div key={sessionIndex} className="bg-gray-50 rounded p-3">
                                <div className="flex items-center justify-between mb-2">
                                  <div className="flex items-center space-x-3">
                                    <p className="text-sm font-medium text-gray-700">
                                      Sesi {session.session_number}
                                    </p>
                                    <p className="text-sm text-gray-600">
                                      {formatTime(session.check_in)} - {session.check_out ? formatTime(session.check_out) : 'Aktif'}
                                    </p>
                                  </div>
                                  <p className="text-sm text-gray-600">
                                    {formatHours(session.total_hours)}
                                  </p>
                                </div>

                                {/* Task Summary - Clickable */}
                                <div className="flex items-center space-x-4 text-xs">
                                  {hasCompletedTasks && (
                                    <button
                                      onClick={() => toggleTaskExpand(dateIndex, sessionIndex)}
                                      className="flex items-center space-x-1 text-green-600 hover:text-green-700 hover:bg-green-50 px-2 py-1 rounded transition-colors"
                                    >
                                      <CheckCircle size={14} />
                                      <span>{session.tasks_completed} selesai</span>
                                      {isTaskExp ? <ChevronUp size={14} /> : <ChevronDown size={14} />}
                                    </button>
                                  )}
                                  {hasIncompleteTasks && (
                                    <button
                                      onClick={() => toggleTaskExpand(dateIndex, sessionIndex)}
                                      className="flex items-center space-x-1 text-red-600 hover:text-red-700 hover:bg-red-50 px-2 py-1 rounded transition-colors"
                                    >
                                      <XCircle size={14} />
                                      <span>{session.tasks_incomplete} belum selesai</span>
                                      {isTaskExp ? <ChevronUp size={14} /> : <ChevronDown size={14} />}
                                    </button>
                                  )}
                                </div>

                                {/* Expanded Task Details */}
                                {isTaskExp && session.tasks && session.tasks.length > 0 && (
                                  <div className="mt-3 pt-3 border-t border-gray-200">
                                    <p className="text-xs font-medium text-gray-700 mb-2">Detail Tugas:</p>
                                    <div className="space-y-2">
                                      {session.tasks.map((task, taskIndex) => (
                                        <div
                                          key={taskIndex}
                                          className={`p-2 rounded text-xs ${
                                            task.is_completed
                                              ? 'bg-green-50 border border-green-200'
                                              : 'bg-red-50 border border-red-200'
                                          }`}
                                        >
                                          <div className="flex items-start space-x-2">
                                            {task.is_completed ? (
                                              <CheckCircle size={14} className="text-green-600 mt-0.5 flex-shrink-0" />
                                            ) : (
                                              <XCircle size={14} className="text-red-600 mt-0.5 flex-shrink-0" />
                                            )}
                                            <div className="flex-1">
                                              <p className={`font-medium ${
                                                task.is_completed ? 'text-green-800' : 'text-red-800'
                                              }`}>
                                                {task.title}
                                              </p>
                                              {!task.is_completed && task.blocker_reason && (
                                                <p className="text-red-700 mt-1 text-xs">
                                                  <span className="font-medium">Blocker:</span> {task.blocker_reason}
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
                  <div className="text-center py-8 text-gray-500">
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
