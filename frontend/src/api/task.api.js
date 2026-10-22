import apiClient from './axios';

export const addTasks = async (attendanceId, tasks) => {
  const response = await apiClient.post('/tasks/add', {
    attendance_id: attendanceId,
    tasks,
  });
  return response.data;
};

export const getIncompleteTasks = async () => {
  const response = await apiClient.get('/tasks/incomplete');
  return response.data;
};

export const getIncompleteTasksFromLastSession = async () => {
  const response = await apiClient.get('/tasks/incomplete-last-session');
  return response.data;
};

// Ticks off tasks that an auto checkout left open (sessions of today and yesterday only).
export const completeAutoCheckoutTasks = async (taskIds) => {
  const response = await apiClient.post('/tasks/complete-after-auto-checkout', {
    task_ids: taskIds,
  });
  return response.data;
};
