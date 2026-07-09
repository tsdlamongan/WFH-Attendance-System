import { useState, useEffect } from 'react';
import { MainLayout } from '../../components/layout/MainLayout';
import { Card } from '../../components/common/Card';
import { Button } from '../../components/common/Button';
import { Loading } from '../../components/common/Loading';
import { getDailyAttendanceReport } from '../../api/manager.api';
import { formatDate, formatTime, formatHours, getTodayDate } from '../../utils/dateHelpers';
import { usePageTitle } from '../../hooks/usePageTitle';
import { Calendar, Clock, CheckCircle, XCircle, ChevronDown, ChevronUp, User, TrendingUp } from 'lucide-react';
import toast from 'react-hot-toast';

export const DailyAttendanceReport = () => {
  usePageTitle('Laporan Harian');
  const [loading, setLoading] = useState(true);
  const [report, setReport] = useState(null);
  const [selectedDate, setSelectedDate] = useState(getTodayDate());
  const [expandedSessions, setExpandedSessions] = useState({});
  const [expandedTasks, setExpandedTasks] = useState({});

  useEffect(() => {
    fetchReport();
  }, []);

  const fetchReport = async () => {
    try {
      setLoading(true);
      const response = await getDailyAttendanceReport(selectedDate);
      
      if (response.success) {
        setReport(response.data);
      }
    } catch (error) {
      console.error('Error fetching daily attendance report:', error);
      toast.error('Gagal mengambil laporan absensi harian');
    } finally {
      setLoading(false);
    }
  };

  const handleDateChange = () => {
    fetchReport();
  };

  const toggleSessionExpand = (employeeIndex, sessionIndex) => {
    const key = `${employeeIndex}-${sessionIndex}`;
    setExpandedSessions(prev => ({
      ...prev,
      [key]: !prev[key]
    }));
  };

  const toggleTaskExpand = (employeeIndex, sessionIndex) => {
    const key = `${employeeIndex}-${sessionIndex}`;
    setExpandedTasks(prev => ({
      ...prev,
      [key]: !prev[key]
    }));
  };

  const isSessionExpanded = (employeeIndex, sessionIndex) => {
    const key = `${employeeIndex}-${sessionIndex}`;
    return expandedSessions[key] || false;
  };

  const isTaskExpanded = (employeeIndex, sessionIndex) => {
    const key = `${employeeIndex}-${sessionIndex}`;
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
        <div className="flex items-center justify-between">
          <div>
            <h1 className="text-display-md sm:text-display-lg">Laporan Absensi Harian</h1>
            <p className="mt-2 font-serif text-body">Lihat absensi semua karyawan untuk tanggal tertentu</p>
          </div>
        </div>

        {/* Date Filter */}
        <Card>
          <div className="flex flex-col gap-4 sm:flex-row sm:items-end sm:gap-4">
            <div className="w-full sm:flex-1">
              <label className="caption-uppercase block mb-2">
                Pilih Tanggal
              </label>
              <input
                type="date"
                value={selectedDate}
                onChange={(e) => setSelectedDate(e.target.value)}
                className="input-field w-full"
              />
            </div>
            <div className="w-full sm:w-auto">
              <Button
                className="w-full sm:w-auto"
                onClick={handleDateChange}
              >
                Lihat Laporan
              </Button>
            </div>
          </div>
        </Card>

        {/* Summary Cards */}
        {report && (
          <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4 sm:gap-6">
            <Card>
              <div className="flex items-center justify-between">
                <div>
                  <p className="caption-uppercase mb-1">Tanggal Laporan</p>
                  <p className="font-mono text-lg text-ink">{formatDate(report.date)}</p>
                </div>
                <div className="w-10 h-10 rounded-xl border-2 border-ink flex items-center justify-center">
                  <Calendar size={20} className="text-muted" />
                </div>
              </div>
            </Card>

            <Card>
              <div className="flex items-center justify-between">
                <div>
                  <p className="caption-uppercase mb-1">Total Karyawan</p>
                  <p className="font-display text-display-md text-ink">{employees.length}</p>
                </div>
                <div className="w-10 h-10 rounded-xl border-2 border-ink flex items-center justify-center">
                  <User size={20} className="text-muted" />
                </div>
              </div>
            </Card>

            <Card>
              <div className="flex items-center justify-between">
                <div>
                  <p className="caption-uppercase mb-1">Jam Wajib</p>
                  <p className="font-display text-display-md text-ink">{requiredHours} jam</p>
                </div>
                <div className="w-10 h-10 rounded-xl border-2 border-ink flex items-center justify-center">
                  <Clock size={20} className="text-muted" />
                </div>
              </div>
            </Card>
          </div>
        )}

        {/* Employees List */}
        <Card title="Detail Absensi Karyawan">
          {employees.length === 0 ? (
            <div className="caption-uppercase text-center py-16">
              Tidak ada catatan absensi ditemukan untuk {formatDate(report?.date)}
            </div>
          ) : (
            <div className="space-y-4">
              {employees.map((employeeData, employeeIndex) => {
                const { employee, daily_total_hours, overtime_hours, status, sessions } = employeeData;
                
                return (
                  <div key={employee.id} className="rounded-xl border-2 border-ink bg-white p-4">
                    {/* Employee Header */}
                    <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between mb-3">
                      <div className="flex items-center space-x-3">
                        <div className="w-10 h-10 rounded-xl border-2 border-ink flex items-center justify-center flex-shrink-0">
                          <User className="text-muted" size={20} />
                        </div>
                        <div>
                          <p className="font-serif text-body-strong">{employee.name}</p>
                          <p className="font-serif text-sm text-muted">{employee.email}</p>
                        </div>
                      </div>
                      <div className="flex flex-wrap items-center gap-4">
                        <div className="text-left sm:text-right">
                          <p className="caption-uppercase">Total Jam</p>
                          <p className="font-mono text-lg text-ink">{formatHours(daily_total_hours)}</p>
                        </div>
                        {overtime_hours > 0 && (
                          <div className="text-left sm:text-right">
                            <p className="caption-uppercase text-warning">Lembur</p>
                            <p className="font-mono text-lg text-warning">{formatHours(overtime_hours)}</p>
                          </div>
                        )}
                        <span className={`badge ${
                          status === 'complete'
                            ? 'badge-success'
                            : status === 'incomplete'
                            ? 'badge-warning'
                            : status === 'overtime'
                            ? 'badge-info'
                            : 'badge-muted'
                        }`}>
                          {status === 'on_leave' ? 'Sedang Cuti' : status === 'complete' ? 'Lengkap' : status === 'incomplete' ? 'Tidak Lengkap' : status === 'overtime' ? 'Lembur' : status}
                        </span>
                      </div>
                    </div>

                    {/* Sessions */}
                    {sessions && sessions.length > 0 ? (
                      <div className="space-y-2 mt-3">
                        <p className="caption-uppercase mb-2">Sesi:</p>
                        {sessions.map((session, sessionIndex) => {
                          const isSessionExp = isSessionExpanded(employeeIndex, sessionIndex);
                          const isTaskExp = isTaskExpanded(employeeIndex, sessionIndex);
                          const hasCompletedTasks = session.tasks_completed > 0;
                          const hasIncompleteTasks = session.tasks_incomplete > 0;

                          return (
                            <div key={sessionIndex} className="rounded-xl border-2 border-ink bg-surface-soft p-3">
                              <div className="flex flex-wrap items-center justify-between gap-2 mb-2">
                                <div className="flex flex-wrap items-center gap-x-3 gap-y-1">
                                  <p className="font-mono text-sm text-ink">
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
                              <div className="flex flex-wrap items-center gap-2 text-xs">
                                {hasCompletedTasks && (
                                  <button
                                    onClick={() => toggleTaskExpand(employeeIndex, sessionIndex)}
                                    className="flex items-center space-x-1 font-mono text-success px-2 py-1 rounded-xl hover:opacity-70 transition-opacity"
                                  >
                                    <CheckCircle size={14} />
                                    <span>{session.tasks_completed} selesai</span>
                                    {isTaskExp ? <ChevronUp size={14} /> : <ChevronDown size={14} />}
                                  </button>
                                )}
                                {hasIncompleteTasks && (
                                  <button
                                    onClick={() => toggleTaskExpand(employeeIndex, sessionIndex)}
                                    className="flex items-center space-x-1 font-mono text-error px-2 py-1 rounded-xl hover:opacity-70 transition-opacity"
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
                                              task.is_completed ? 'text-success' : 'text-error'
                                            }`}>
                                              {task.title}
                                            </p>
                                            {!task.is_completed && task.blocker_reason && (
                                              <p className="font-serif text-error mt-1 text-xs">
                                                <span>Blocker:</span> {task.blocker_reason}
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
                    ) : (
                      <p className="font-serif text-sm text-muted mt-2">Tidak ada sesi tercatat</p>
                    )}
                  </div>
                );
              })}
            </div>
          )}
        </Card>
      </div>
    </MainLayout>
  );
};
