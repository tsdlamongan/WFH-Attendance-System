import { useState, useEffect } from 'react';
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

export const EmployeeDashboard = () => {
  usePageTitle('Dashboard');
  
  const [loading, setLoading] = useState(true);
  const [todayStatus, setTodayStatus] = useState(null);
  const [showCheckInModal, setShowCheckInModal] = useState(false);
  const [showCheckOutModal, setShowCheckOutModal] = useState(false);
  const [actionLoading, setActionLoading] = useState(false);
  const [fetchError, setFetchError] = useState(false);

  useEffect(() => {
    fetchTodayStatus();
  }, []);

  const fetchTodayStatus = async (retryCount = 0) => {
    try {
      setLoading(true);
      setFetchError(false);
      const response = await getTodayStatus();
      if (response.success) {
        console.log('Today Status Response:', response.data);
        console.log('Current Session:', response.data?.current_session);
        console.log('Tasks:', response.data?.current_session?.tasks);
        
        // Validate response data structure
        if (response.data && typeof response.data.is_checked_in === 'boolean') {
          setTodayStatus(response.data);
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
      
      // Retry once after 2 seconds if first attempt fails
      if (retryCount === 0) {
        setTimeout(() => {
          console.log('Retrying fetch today status...');
          fetchTodayStatus(1);
        }, 2000);
      } else {
        toast.error('Gagal mengambil status. Silakan refresh halaman.');
      }
    } finally {
      setLoading(false);
    }
  };

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
      const message = error.response?.data?.message || 'Gagal melakukan check in';
      toast.error(message);
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
      <div className="space-y-6">
        {/* Error Banner */}
        {fetchError && (
          <div className="bg-red-50 border border-red-200 rounded-lg p-4 flex items-center justify-between">
            <div className="flex items-center space-x-3">
              <AlertCircle className="text-red-500" size={20} />
              <div>
                <p className="text-red-800 font-medium">Gagal memuat data status</p>
                <p className="text-red-600 text-sm">Ada masalah saat mengambil data. Silakan refresh halaman.</p>
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
                <div className="bg-yellow-50 border border-yellow-200 rounded-lg p-4 flex items-center space-x-3">
                  <AlertCircle className="text-yellow-600" size={20} />
                  <div>
                    <p className="text-yellow-800 font-medium">⚠️ Anda masih dalam sesi check-in dari hari sebelumnya</p>
                    <p className="text-yellow-700 text-sm">
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
        <div className="flex items-center justify-between">
          <div>
            <h1 className="text-3xl font-bold text-gray-900">Dashboard</h1>
            <p className="text-gray-600 mt-1">
              {new Date().toLocaleDateString('en-US', { 
                weekday: 'long', 
                year: 'numeric', 
                month: 'long', 
                day: 'numeric' 
              })}
            </p>
          </div>

          {todayStatus === null ? (
            <Button disabled className="flex items-center space-x-2">
              <Clock size={20} className="animate-spin" />
              <span>Loading...</span>
            </Button>
          ) : isCheckedIn ? (
            <Button
              onClick={() => setShowCheckOutModal(true)}
              variant="danger"
              className="flex items-center space-x-2"
              disabled={!currentSession?.id}
            >
              <StopCircle size={20} />
              <span>Check Out</span>
            </Button>
          ) : (
            <Button
              onClick={() => setShowCheckInModal(true)}
              className="flex items-center space-x-2"
            >
              <PlayCircle size={20} />
              <span>Check In</span>
            </Button>
          )}
        </div>

        {/* Status Cards */}
        <div className="grid grid-cols-1 md:grid-cols-3 gap-6">
          {/* Current Status */}
          <Card>
            <div className="flex items-center justify-between">
              <div>
                <p className="text-sm text-gray-600 mb-1">Status Saat Ini</p>
                <p className={`text-2xl font-bold ${isCheckedIn ? 'text-green-600' : 'text-gray-400'}`}>
                  {isCheckedIn ? 'Sudah Check In' : 'Belum Check In'}
                </p>
              </div>
              <div className={`p-3 rounded-full ${isCheckedIn ? 'bg-green-100' : 'bg-gray-100'}`}>
                <Clock size={24} className={isCheckedIn ? 'text-green-600' : 'text-gray-400'} />
              </div>
            </div>
          </Card>

          {/* Jam Kerja Hari Ini */}
          <Card>
            <div className="flex items-center justify-between">
              <div>
                <p className="text-sm text-gray-600 mb-1">Jam Kerja Hari Ini</p>
                <p className="text-2xl font-bold text-primary-600">
                  {formatHours(todayTotalHours)}
                </p>
              </div>
              <div className="p-3 rounded-full bg-primary-100">
                <CheckCircle size={24} className="text-primary-600" />
              </div>
            </div>
          </Card>

          {/* Jam Tersisa */}
          <Card>
            <div className="flex items-center justify-between">
              <div>
                <p className="text-sm text-gray-600 mb-1">Jam Tersisa</p>
                <p className={`text-2xl font-bold ${remainingHours > 0 ? 'text-orange-600' : 'text-green-600'}`}>
                  {formatHours(remainingHours)}
                </p>
              </div>
              <div className={`p-3 rounded-full ${remainingHours > 0 ? 'bg-orange-100' : 'bg-green-100'}`}>
                <AlertCircle size={24} className={remainingHours > 0 ? 'text-orange-600' : 'text-green-600'} />
              </div>
            </div>
          </Card>
        </div>

        {/* Progress Bar */}
        <Card title="Progress Hari Ini">
          <div className="space-y-2">
            <div className="flex justify-between text-sm text-gray-600">
              <span>{formatHours(todayTotalHours)} sudah bekerja</span>
              <span>{requiredHours} jam wajib</span>
            </div>
            <div className="w-full bg-gray-200 rounded-full h-4">
              <div
                className={`h-4 rounded-full transition-all duration-500 ${
                  progressPercentage >= 100 ? 'bg-green-500' : 'bg-primary-500'
                }`}
                style={{ width: `${progressPercentage}%` }}
              ></div>
            </div>
            <p className="text-sm text-gray-600 text-center">
              {progressPercentage >= 100 
                ? '✅ Target harian tercapai!' 
                : `${progressPercentage.toFixed(0)}% selesai`}
            </p>
          </div>
        </Card>

        {/* Current Session */}
        {isCheckedIn && currentSession && (
          <Card title="Sesi Saat Ini">
            <div className="space-y-4">
              <div className="flex items-center justify-between">
                <div>
                  <p className="text-sm text-gray-600">Waktu Check In</p>
                  <p className="text-lg font-semibold">{formatTime(currentSession.check_in)}</p>
                </div>
                <div>
                  <p className="text-sm text-gray-600">Waktu Berlalu</p>
                  <p className="text-lg font-semibold text-primary-600">
                    {formatHours(currentSession.elapsed_hours)}
                  </p>
                </div>
              </div>

              {currentSession.tasks && currentSession.tasks.length > 0 && (
                <div>
                  <p className="text-sm font-medium text-gray-700 mb-2">Tugas Hari Ini:</p>
                  <ul className="space-y-2">
                    {currentSession.tasks.map((task, index) => (
                      <li key={index} className="flex items-start space-x-2">
                        <span className="text-primary-600 mt-1">🎯</span>
                        <span className="text-gray-700 mt-1">{task.title}</span>
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
                <div key={index} className="flex items-center justify-between p-3 bg-gray-50 rounded-lg">
                  <div>
                    <p className="text-sm text-gray-600">
                      {formatTime(session.check_in)} - {formatTime(session.check_out)}
                    </p>
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
