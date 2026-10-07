import apiClient from './axios';

// Active projects of the user's team ({ id, name }[]): the choices offered when logging tasks.
export const getActiveProjects = async () => {
  const response = await apiClient.get('/projects');
  return response.data;
};
