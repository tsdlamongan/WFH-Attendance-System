import { useState, useEffect } from 'react';
import { MainLayout } from '../../components/layout/MainLayout';
import { Card } from '../../components/common/Card';
import { Button } from '../../components/common/Button';
import { Loading } from '../../components/common/Loading';
import { getMyReport } from '../../api/report.api';
import { formatDate, formatTime, formatHours, getMonthStart, getMonthEnd } from '../../utils/dateHelpers';
import { usePageTitle } from '../../hooks/usePageTitle';
import { Calendar, TrendingUp, Clock, CheckCircle, XCircle, ChevronDown, ChevronUp, AlertTriangle } from 'lucide-react';
import toast from 'react-hot-toast';

export const MyReport = () => {
  usePageTitle('Laporan Saya');
  const [loading, setLoading] = useState(true);
  const [report, setReport] = useState(null);
  const [startDate, setStartDate] = useState(getMonthStart());
  const [endDate, setEndDate] = useState(getMonthEnd());
  const [expandedSessions, setExpandedSessions] = useState({});

  useEffect(() => {
    fetchReport();
  }, []);

  const fetchReport = async () => {
    try {
      setLoading(true);
      const response = await getMyReport(startDate, endDate);
      
      if (response.success) {
        setReport(response.data);
      }
    } catch (error) {
      console.error('Error fetching report:', error);
      toast.error('Gagal mengambil laporan');
    } finally {
      setLoading(false);
    }
  };

  const handleFilter = () => {
    fetchReport();
  };

  const toggleSessionExpand = (attendanceIndex, sessionIndex) => {
    const key = `${attendanceIndex}-${sessionIndex}`;
    setExpandedSessions(prev => ({
      ...prev,
      [key]: !prev[key]
    }));
  };

  const isSessionExpanded = (attendanceIndex, sessionIndex) => {
    const key = `${attendanceIndex}-${sessionIndex}`;
    return expandedSessions[key] || false;
  };

  if (loading) {
    return (
      <MainLayout>
        <Loading />
      </MainLayout>
    );
  }

  const summary = report?.summary || {};
  const attendances = report?.attendances || [];

  return (
    <MainLayout>
      <div className="space-y-6 sm:space-y-8">
        {/* Header */}
        <div>
          <h1 className="text-display-md sm:text-display-lg">Laporan Kerja Saya</h1>
          <p className="mt-2 font-serif text-body">Lacak jam kerja dan produktivitas Anda</p>
        </div>

        {/* Date Filter */}
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

        {/* Summary Cards - Row 1 */}
        <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 sm:gap-6">
          <Card>
            <div className="flex items-center justify-between">
              <div>
                <p className="caption-uppercase mb-1">Hari Kerja (Filter)</p>
                <p className="font-display text-display-md text-ink">{summary.working_days || 0} hari</p>
              </div>
              <div className="w-10 h-10 rounded-full border border-hairline-strong flex items-center justify-center">
                <Calendar size={24} className="text-muted" />
              </div>
            </div>
          </Card>

          <Card>
            <div className="flex items-center justify-between">
              <div>
                <p className="caption-uppercase mb-1">Jam Semestinya</p>
                <p className="font-display text-display-md text-ink">
                  {summary.expected_hours || 0} jam
                </p>
                <p className="font-mono text-xs text-muted">{summary.working_days || 0} hari × 8 jam</p>
              </div>
              <div className="w-10 h-10 rounded-full border border-hairline-strong flex items-center justify-center">
                <Clock size={24} className="text-muted" />
              </div>
            </div>
          </Card>

          <Card>
            <div className="flex items-center justify-between">
              <div>
                <p className="caption-uppercase mb-1">Total Jam Kerja</p>
                <p className="font-display text-display-md text-ink">
                  {formatHours(summary.total_hours || 0)}
                </p>
              </div>
              <div className="w-10 h-10 rounded-full border border-hairline-strong flex items-center justify-center">
                <Clock size={24} className="text-muted" />
              </div>
            </div>
          </Card>

          <Card>
            <div className="flex items-center justify-between">
              <div>
                <p className="caption-uppercase mb-1">Penyelesaian Tugas</p>
                <p className="font-display text-display-md text-ink">
                  {(summary.task_completion_rate || 0).toFixed(1)}%
                </p>
              </div>
              <div className="w-10 h-10 rounded-full border border-hairline-strong flex items-center justify-center">
                <CheckCircle size={24} className="text-muted" />
              </div>
            </div>
          </Card>
        </div>

        {/* Summary Cards - Row 2 */}
        <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 sm:gap-6">
          <Card>
            <div className="flex items-center justify-between">
              <div>
                <p className="caption-uppercase mb-1">Hari Masuk</p>
                <p className="font-display text-display-md text-success">{summary.total_days_worked || 0} hari</p>
              </div>
              <div className="w-10 h-10 rounded-full border border-hairline-strong flex items-center justify-center">
                <Calendar size={24} className="text-muted" />
              </div>
            </div>
          </Card>

          <Card>
            <div className="flex items-center justify-between">
              <div>
                <p className="caption-uppercase mb-1">Hari Cuti</p>
                <p className="font-display text-display-md text-ink">{summary.total_leave_days || 0} hari</p>
              </div>
              <div className="w-10 h-10 rounded-full border border-hairline-strong flex items-center justify-center">
                <Calendar size={24} className="text-muted" />
              </div>
            </div>
          </Card>

          <Card>
            <div className="flex items-center justify-between">
              <div>
                <p className="caption-uppercase mb-1">Jam Lembur</p>
                <p className="font-display text-display-md text-warning">
                  {summary.overtime_hours > 0 ? `+${formatHours(summary.overtime_hours)}` : '-'}
                </p>
              </div>
              <div className="w-10 h-10 rounded-full border border-hairline-strong flex items-center justify-center">
                <TrendingUp size={24} className="text-muted" />
              </div>
            </div>
          </Card>

          <Card>
            <div className="flex items-center justify-between">
              <div>
                <p className="caption-uppercase mb-1">Kurang Jam</p>
                <p className="font-display text-display-md text-error">
                  {summary.deficit_hours > 0 ? `-${formatHours(summary.deficit_hours)}` : '-'}
                </p>
              </div>
              <div className="w-10 h-10 rounded-full border border-hairline-strong flex items-center justify-center">
                <AlertTriangle size={24} className="text-muted" />
              </div>
            </div>
          </Card>
        </div>

        {/* Attendance Details */}
        <Card title="Detail Absensi">
          {attendances.length === 0 ? (
            <div className="caption-uppercase text-center py-16">
              Tidak ada catatan absensi ditemukan untuk periode yang dipilih
            </div>
          ) : (
            <div className="space-y-4">
              {attendances.map((attendance, attendanceIndex) => (
                <div key={attendanceIndex} className="border border-hairline rounded-none p-4">
                  <div className="flex items-center justify-between mb-3">
                    <div>
                      <p className="font-mono text-sm text-ink">{formatDate(attendance.date)}</p>
                      <p className="font-mono text-sm text-muted">
                        Total: {formatHours(attendance.daily_total_hours)}
                      </p>
                    </div>
                    <span className={`badge ${
                      attendance.status === 'complete'
                        ? 'badge-success'
                        : attendance.status === 'incomplete'
                        ? 'badge-warning'
                        : attendance.status === 'on_leave'
                        ? 'badge-info'
                        : 'badge-info'
                    }`}>
                      {attendance.status === 'complete'
                        ? 'Lengkap'
                        : attendance.status === 'incomplete'
                        ? 'Tidak Lengkap'
                        : attendance.status === 'on_leave'
                        ? 'Cuti'
                        : 'Lembur'}
                    </span>
                  </div>

                  {/* Sessions */}
                  <div className="space-y-2">
                    {attendance.sessions.map((session, sessionIndex) => {
                      const isExpanded = isSessionExpanded(attendanceIndex, sessionIndex);
                      const hasCompletedTasks = session.tasks_completed > 0;
                      const hasIncompleteTasks = session.tasks_incomplete > 0;

                      return (
                        <div key={sessionIndex} className="bg-surface-soft rounded-none p-3">
                          <div className="flex items-center justify-between mb-2">
                            <p className="caption-uppercase">
                              Sesi {sessionIndex + 1}
                            </p>
                            <p className="font-mono text-sm text-muted">
                              {formatHours(session.total_hours)}
                            </p>
                          </div>
                          <p className="font-mono text-sm text-muted mb-2">
                            {formatTime(session.check_in)} - {formatTime(session.check_out)}
                          </p>

                          {/* Task Summary - Clickable */}
                          <div className="flex items-center space-x-4 font-mono text-xs">
                            {hasCompletedTasks && (
                              <button
                                onClick={() => toggleSessionExpand(attendanceIndex, sessionIndex)}
                                className="flex items-center space-x-1 text-success hover:text-ink px-2 py-1 rounded-none transition-colors"
                              >
                                <CheckCircle size={14} />
                                <span>{session.tasks_completed} selesai</span>
                                {isExpanded ? <ChevronUp size={14} /> : <ChevronDown size={14} />}
                              </button>
                            )}
                            {hasIncompleteTasks && (
                              <button
                                onClick={() => toggleSessionExpand(attendanceIndex, sessionIndex)}
                                className="flex items-center space-x-1 text-error hover:text-ink px-2 py-1 rounded-none transition-colors"
                              >
                                <XCircle size={14} />
                                <span>{session.tasks_incomplete} belum selesai</span>
                                {isExpanded ? <ChevronUp size={14} /> : <ChevronDown size={14} />}
                              </button>
                            )}
                          </div>

                          {/* Expanded Task Details */}
                          {isExpanded && session.tasks && session.tasks.length > 0 && (
                            <div className="mt-3 pt-3 border-t border-hairline">
                              <p className="caption-uppercase mb-2">Detail Tugas:</p>
                              <div className="space-y-2">
                                {session.tasks.map((task, taskIndex) => (
                                  <div
                                    key={taskIndex}
                                    className={`p-2 rounded-none text-xs bg-surface-elevated border ${
                                      task.is_completed
                                        ? 'border-hairline'
                                        : 'border-error'
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
                </div>
              ))}
            </div>
          )}
        </Card>
      </div>
    </MainLayout>
  );
};
