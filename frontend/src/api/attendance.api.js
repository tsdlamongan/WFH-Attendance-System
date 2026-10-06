import apiClient from './axios';

// overtime: { reason, planned_minutes } — required by the backend when today's status reports
// requires_overtime_request (required hours already met and no pending/approved overtime yet).
export const checkIn = async (tasks, overtime = null) => {
  const payload = overtime ? { tasks, overtime } : { tasks };
  const response = await apiClient.post('/attendance/check-in', payload);
  return response.data;
};

export const checkOut = async (attendanceId, tasks) => {
  const response = await apiClient.post('/attendance/check-out', {
    attendance_id: attendanceId,
    tasks,
  });
  return response.data;
};

export const getTodayStatus = async () => {
  const response = await apiClient.get('/attendance/today');
  return response.data;
};

export const getMyAttendanceHistory = async (startDate, endDate) => {
  const response = await apiClient.get('/attendances/my-history', {
    params: { start_date: startDate, end_date: endDate },
  });
  return response.data;
};
