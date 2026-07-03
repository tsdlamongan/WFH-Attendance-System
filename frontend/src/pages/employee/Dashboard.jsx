import { useState, useEffect, useRef, useCallback } from 'react';
import { MainLayout } from '../../components/layout/MainLayout';
import { Card } from '../../components/common/Card';
import { Button } from '../../components/common/Button';
import { Loading } from '../../components/common/Loading';
import { CheckInModal } from '../../components/attendance/CheckInModal';
import { CheckOutModal } from '../../components/attendance/CheckOutModal';
import { getTodayStatus, checkIn, checkOut } from '../../api/attendance.api';
import { formatTime, formatHours } from '../../utils/dateHelpers';
import { REQUIRED_WORK_HOURS } from '../../utils/constants';
import { usePageTitle } from '../../hooks/usePageTitle';
import { Clock, CheckCircle, AlertCircle, PlayCircle, StopCircle } from 'lucide-react';
import toast from 'react-hot-toast';

const POLL_INTERVAL_MS = 30_000;

export const EmployeeDashboard = () => {
  usePageTitle('Dashboard');
  
  const [loading, setLoading] = useState(true);
  const [todayStatus, setTodayStatus] = useState(null);
  const [showCheckInModal, setShowCheckInModal] = useState(false);
  const [showCheckOutModal, setShowCheckOutModal] = useState(false);
  const [actionLoading, setActionLoading] = useState(false);
  const [fetchError, setFetchError] = useState(false);
  const shownAutoCheckoutIdRef = useRef(null);

  const fetchTodayStatus = useCallback(async (retryCount = 0) => {
    try {
      setFetchError(false);
      const response = await getTodayStatus();
      if (response.success) {
        if (response.data && typeof response.data.is_checked_in === 'boolean') {
          setTodayStatus((prev) => {
            const prevWasCheckedIn = prev?.is_checked_in === true;
            const nowCheckedIn = response.data.is_checked_in === true;
            const autoCheckout = response.data.last_auto_checkout;

            if (prevWasCheckedIn && !nowCheckedIn && autoCheckout && autoCheckout.id !== shownAutoCheckoutIdRef.current) {
              shownAutoCheckoutIdRef.current = autoCheckout.id;
              toast('Anda telah otomatis checkout karena sudah mencapai jam kerja wajib. Silakan check-in kembali jika ingin lembur.', {
                duration: 8000,
                icon: '⏰',
              });
            }

            return response.data;
          });
          setFetchError(false);
        } else {
          console.error('Invalid response structure:', response.data);
          toast.error('Data tidak valid. Silakan refresh halaman.');
          setFetchError(true);
        }
      }
    } catch (error) {
      console.error('Error fetching today status:', error);
      setFetchError(true);
      
      if (retryCount === 0) {
        setTimeout(() => {
          fetchTodayStatus(1);
        }, 2000);
      } else {
        toast.error('Gagal mengambil status. Silakan refresh halaman.');
      }
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    fetchTodayStatus();
  }, [fetchTodayStatus]);

  useEffect(() => {
    const interval = setInterval(() => {
      fetchTodayStatus();
    }, POLL_INTERVAL_MS);

    return () => clearInterval(interval);
  }, [fetchTodayStatus]);

  const handleCheckIn = async (tasks) => {
    try {
      setActionLoading(true);
      const response = await checkIn(tasks);
      
      if (response.success) {
        toast.success(response.message);
        setShowCheckInModal(false);
        fetchTodayStatus();
      }
    } catch (error) {
      const msg = error.response?.data?.message || '';
      const isAlreadyCheckedIn = typeof msg === 'string' && (
        msg.includes('already checked in') || msg.includes('Please check out first')
      );
      const message = isAlreadyCheckedIn
        ? 'Anda masih dalam sesi check-in sebelumnya. Silakan lakukan checkout terlebih dahulu.'
        : (msg || 'Gagal melakukan check in');
      toast.error(message, isAlreadyCheckedIn ? { duration: 6000 } : undefined);
    } finally {
      setActionLoading(false);
    }
  };

  const handleCheckOut = async (attendanceId, tasks) => {
    try {
      setActionLoading(true);
      const response = await checkOut(attendanceId, tasks);
      
      if (response.success) {
        toast.success(response.message);
        setShowCheckOutModal(false);
        fetchTodayStatus();
      }
    } catch (error) {
      const message = error.response?.data?.message || 'Failed to check out';
      toast.error(message);
    } finally {
      setActionLoading(false);
    }
  };

  if (loading) {
    return (
      <MainLayout>
        <Loading />
      </MainLayout>
    );
  }

  // Explicitly check for boolean value to avoid undefined issues
  const isCheckedIn = todayStatus?.is_checked_in === true;
  const currentSession = todayStatus?.current_session;
  const todayTotalHours = todayStatus?.today_total_hours || 0;
  // Use required_hours from API (which uses config) or fallback to constant
  const requiredHours = todayStatus?.required_hours || REQUIRED_WORK_HOURS;
  const remainingHours = Math.max(0, requiredHours - todayTotalHours);
  const progressPercentage = Math.min(100, (todayTotalHours / requiredHours) * 100);
  
  // Debug log for troubleshooting
  console.log('Dashboard State:', {
    isCheckedIn,
    hasCurrentSession: !!currentSession,
    currentSessionId: currentSession?.id,
    tasksCount: currentSession?.tasks?.length || 0
  });

  return (
    <MainLayout>
      <div className="space-y-6 sm:space-y-8">
        {/* Error Banner */}
        {fetchError && (
          <div className="border border-error bg-surface-soft rounded-none p-4 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <div className="flex items-start space-x-3">
              <AlertCircle className="text-error shrink-0" size={20} />
              <div>
                <p className="font-serif text-error">Gagal memuat data status</p>
                <p className="font-serif text-sm text-body">Ada masalah saat mengambil data. Silakan refresh halaman.</p>
              </div>
            </div>
            <Button onClick={() => fetchTodayStatus()} variant="secondary" size="sm">
              Refresh
            </Button>
          </div>
        )}

        {/* Cross-Date Warning Banner */}
        {isCheckedIn && currentSession && currentSession.check_in && (
          (() => {
            const checkInDate = new Date(currentSession.check_in).toDateString();
            const today = new Date().toDateString();
            const isCrossDate = checkInDate !== today;
            
            if (isCrossDate) {
              return (
                <div className="border border-warning bg-surface-soft rounded-none p-4 flex items-start space-x-3">
                  <AlertCircle className="text-warning shrink-0" size={20} />
                  <div>
                    <p className="font-serif text-warning">⚠️ Anda masih dalam sesi check-in dari hari sebelumnya</p>
                    <p className="font-serif text-sm text-body">
                      Check-in: {new Date(currentSession.check_in).toLocaleString('id-ID')}. 
                      Silakan checkout untuk menyelesaikan sesi kerja Anda.
                    </p>
                  </div>
                </div>
              );
            }
            return null;
          })()
        )}
        
        {/* Header */}
        <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
          <div>
            <h1 className="text-display-md sm:text-display-lg">Dashboard</h1>
            <p className="mt-2 caption-uppercase">
              {new Date().toLocaleDateString('en-US', {
                weekday: 'long',
                year: 'numeric',
                month: 'long',
                day: 'numeric'
              })}
            </p>
          </div>

          {todayStatus === null ? (
            <Button disabled className="w-full sm:w-auto">
              <Clock size={20} className="animate-spin" />
              <span>Loading...</span>
            </Button>
          ) : isCheckedIn ? (
            <Button
              onClick={() => setShowCheckOutModal(true)}
              variant="danger"
              className="w-full sm:w-auto"
              disabled={!currentSession?.id}
            >
              <StopCircle size={20} />
              <span>Check Out</span>
            </Button>
          ) : (
            <Button
              onClick={() => setShowCheckInModal(true)}
              className="w-full sm:w-auto"
            >
              <PlayCircle size={20} />
              <span>Check In</span>
            </Button>
          )}
        </div>

        {/* Status Cards */}
        <div className="grid grid-cols-1 gap-4 sm:gap-6 md:grid-cols-3">
          {/* Current Status */}
          <Card>
            <div className="flex items-center justify-between gap-4">
              <div>
                <p className="caption-uppercase mb-1">Status Saat Ini</p>
                <p className={`font-display uppercase text-display-sm sm:text-display-md ${isCheckedIn ? 'text-success' : 'text-muted'}`}>
                  {isCheckedIn ? 'Sudah Check In' : 'Belum Check In'}
                </p>
              </div>
              <div className="w-12 h-12 shrink-0 rounded-full border border-hairline-strong flex items-center justify-center">
                <Clock size={24} className={isCheckedIn ? 'text-success' : 'text-muted'} />
              </div>
            </div>
          </Card>

          {/* Jam Kerja Hari Ini */}
          <Card>
            <div className="flex items-center justify-between gap-4">
              <div>
                <p className="caption-uppercase mb-1">Jam Kerja Hari Ini</p>
                <p className="font-display uppercase text-display-sm sm:text-display-md text-ink">
                  {formatHours(todayTotalHours)}
                </p>
              </div>
              <div className="w-12 h-12 shrink-0 rounded-full border border-hairline-strong flex items-center justify-center">
                <CheckCircle size={24} className="text-muted" />
              </div>
            </div>
          </Card>

          {/* Jam Tersisa */}
          <Card>
            <div className="flex items-center justify-between gap-4">
              <div>
                <p className="caption-uppercase mb-1">Jam Tersisa</p>
                <p className={`font-display uppercase text-display-sm sm:text-display-md ${remainingHours > 0 ? 'text-warning' : 'text-success'}`}>
                  {formatHours(remainingHours)}
                </p>
              </div>
              <div className="w-12 h-12 shrink-0 rounded-full border border-hairline-strong flex items-center justify-center">
                <AlertCircle size={24} className={remainingHours > 0 ? 'text-warning' : 'text-success'} />
              </div>
            </div>
          </Card>
        </div>

        {/* Progress Bar */}
        <Card title="Progress Hari Ini">
          <div className="space-y-3">
            <div className="flex justify-between gap-4 caption-uppercase">
              <span>{formatHours(todayTotalHours)} sudah bekerja</span>
              <span>{requiredHours} jam wajib</span>
            </div>
            <div className="w-full bg-surface-elevated rounded-none h-1">
              <div
                className={`h-1 rounded-none ${
                  progressPercentage >= 100 ? 'bg-success' : 'bg-ink'
                }`}
                style={{ width: `${progressPercentage}%` }}
              ></div>
            </div>
            <p className="caption-uppercase text-center">
              {progressPercentage >= 100
                ? '✅ Target harian tercapai!'
                : `${progressPercentage.toFixed(0)}% selesai`}
            </p>
          </div>
        </Card>

        {/* Current Session */}
        {isCheckedIn && currentSession && (
          <Card title="Sesi Saat Ini">
            <div className="space-y-4 sm:space-y-6">
              <div className="flex items-center justify-between gap-4">
                <div>
                  <p className="caption-uppercase mb-1">Waktu Check In</p>
                  <p className="font-mono text-lg text-ink">{formatTime(currentSession.check_in)}</p>
                </div>
                <div>
                  <p className="caption-uppercase mb-1">Waktu Berlalu</p>
                  <p className="font-mono text-lg text-ink">
                    {formatHours(currentSession.elapsed_hours)}
                  </p>
                </div>
              </div>

              {currentSession.tasks && currentSession.tasks.length > 0 && (
                <div>
                  <p className="caption-uppercase mb-2">Tugas Hari Ini:</p>
                  <ul className="space-y-2">
                    {currentSession.tasks.map((task, index) => (
                      <li key={index} className="flex items-start space-x-2">
                        <span className="text-ink mt-1">🎯</span>
                        <span className="font-serif text-body mt-1">{task.title}</span>
                      </li>
                    ))}
                  </ul>
                </div>
              )}
            </div>
          </Card>
        )}

        {/* Previous Sessions Today */}
        {todayStatus?.previous_sessions && todayStatus.previous_sessions.length > 0 && (
          <Card title="Sesi Sebelumnya Hari Ini">
            <div className="space-y-3">
              {todayStatus.previous_sessions.map((session, index) => (
                <div key={index} className="flex items-center justify-between gap-4 p-3 border border-hairline bg-surface-soft rounded-none">
                  <div className="flex flex-wrap items-center gap-2">
                    <p className="font-mono text-sm text-muted">
                      {formatTime(session.check_in)} - {formatTime(session.check_out)}
                    </p>
                    {session.is_auto_checkout && (
                      <span className="badge badge-warning">
                        Auto Checkout
                      </span>
                    )}
                  </div>
                  <div>
                    <span className="badge badge-info">{formatHours(session.total_hours)}</span>
                  </div>
                </div>
              ))}
            </div>
          </Card>
        )}
      </div>

      {/* Modals */}
      <CheckInModal
        isOpen={showCheckInModal}
        onClose={() => setShowCheckInModal(false)}
        onSubmit={handleCheckIn}
        loading={actionLoading}
      />

      <CheckOutModal
        isOpen={showCheckOutModal}
        onClose={() => setShowCheckOutModal(false)}
        onSubmit={handleCheckOut}
        loading={actionLoading}
        tasks={currentSession?.tasks || []}
        attendanceId={currentSession?.id || null}
      />
    </MainLayout>
  );
};
