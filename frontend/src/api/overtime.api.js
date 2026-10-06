import apiClient from './axios';

// Employee: request overtime in advance (today only).
export const requestOvertime = async (reason, plannedMinutes) => {
  const response = await apiClient.post('/overtimes', {
    reason,
    planned_minutes: plannedMinutes,
  });
  return response.data;
};

export const getMyOvertimes = async () => {
  const response = await apiClient.get('/overtimes/my-requests');
  return response.data;
};
