import apiClient from './axios';

// Dashboard
export const getManagerDashboard = async (date) => {
  const response = await apiClient.get('/manager/dashboard', {
    params: { date },
  });
  return response.data;
};

export const getEmployeeReport = async (userId, startDate, endDate) => {
  const response = await apiClient.get(`/manager/reports/employee/${userId}`, {
    params: { start_date: startDate, end_date: endDate },
  });
  return response.data;
};

export const getDailyAttendanceReport = async (date) => {
  const response = await apiClient.get('/manager/reports/daily-attendance', {
    params: { date },
  });
  return response.data;
};

export const getMonthlyAttendanceReport = async (startDate, endDate) => {
  const response = await apiClient.get('/manager/reports/monthly-attendance', {
    params: { start_date: startDate, end_date: endDate },
  });
  return response.data;
};

export const getCheckInTimeReport = async (startDate, endDate) => {
  const response = await apiClient.get('/manager/reports/check-in-time', {
    params: { start_date: startDate, end_date: endDate },
  });
  return response.data;
};

// Attendance Management
export const getAllAttendances = async (startDate, endDate, page = 1, perPage = 10, userId = null) => {
  const params = { start_date: startDate, end_date: endDate, page, per_page: perPage };
  if (userId) {
    params.user_id = userId;
  }
  const response = await apiClient.get('/manager/attendances', { params });
  return response.data;
};

export const createAttendance = async (data) => {
  const response = await apiClient.post('/manager/attendances', {
    user_id: data.userId,
    date: data.date,
    check_in: data.checkIn,
    check_out: data.checkOut || null,
    tasks: data.tasks,
    reason: data.reason,
  });
  return response.data;
};

export const editAttendance = async (id, date, checkIn, checkOut, reason) => {
  const response = await apiClient.put(`/manager/attendances/${id}`, {
    date,
    check_in: checkIn,
    check_out: checkOut,
    reason,
  });
  return response.data;
};

export const deleteAttendance = async (id, reason) => {
  const response = await apiClient.delete(`/manager/attendances/${id}`, {
    data: { reason },
  });
  return response.data;
};

export const updateTask = async (id, taskData) => {
  const response = await apiClient.put(`/manager/tasks/${id}`, taskData);
  return response.data;
};

// User Management
export const getAllUsers = async (page = 1, perPage = 10) => {
  const response = await apiClient.get('/manager/users', {
    params: { page, per_page: perPage },
  });
  return response.data;
};

export const searchUsers = async (query, limit = 10) => {
  const response = await apiClient.get('/manager/users/search', {
    params: { q: query, limit },
  });
  return response.data;
};

export const createUser = async (userData) => {
  const response = await apiClient.post('/manager/users', userData);
  return response.data;
};

export const updateUser = async (id, userData) => {
  const response = await apiClient.put(`/manager/users/${id}`, userData);
  return response.data;
};

export const deleteUser = async (id) => {
  const response = await apiClient.delete(`/manager/users/${id}`);
  return response.data;
};

export const toggleUserDisabled = async (id) => {
  const response = await apiClient.patch(`/manager/users/${id}/toggle-disabled`);
  return response.data;
};

// Holiday Management
export const createHoliday = async (holidayData) => {
  const response = await apiClient.post('/manager/holidays', holidayData);
  return response.data;
};

export const updateHoliday = async (id, holidayData) => {
  const response = await apiClient.put(`/manager/holidays/${id}`, holidayData);
  return response.data;
};

export const deleteHoliday = async (id) => {
  const response = await apiClient.delete(`/manager/holidays/${id}`);
  return response.data;
};

// Leave Management
export const getAllLeaveRequests = async (status) => {
  const response = await apiClient.get('/manager/leaves', {
    params: { status },
  });
  return response.data;
};

export const editLeave = async (id, data) => {
  const response = await apiClient.put(`/manager/leaves/${id}`, data);
  return response.data;
};

export const approveLeave = async (id, notes) => {
  const response = await apiClient.put(`/manager/leaves/${id}/approve`, {
    notes,
  });
  return response.data;
};

export const rejectLeave = async (id, notes) => {
  const response = await apiClient.put(`/manager/leaves/${id}/reject`, {
    notes,
  });
  return response.data;
};

// Leave Quota Management
export const getLeaveQuotas = async (year) => {
  const response = await apiClient.get('/manager/leave-quotas', {
    params: { year },
  });
  return response.data;
};

export const updateLeaveQuota = async (userId, year, quotaDays) => {
  const response = await apiClient.put(`/manager/leave-quotas/${userId}`, {
    year,
    quota_days: quotaDays,
  });
  return response.data;
};

export const bulkUpdateLeaveQuotas = async (year, quotaDays) => {
  const response = await apiClient.post('/manager/leave-quotas/bulk', {
    year,
    quota_days: quotaDays,
  });
  return response.data;
};

// Activity Logs
export const getActivityLogs = async (filters, page = 1, perPage = 10) => {
  const response = await apiClient.get('/manager/activity-logs', {
    params: { ...filters, page, per_page: perPage },
  });
  return response.data;
};
