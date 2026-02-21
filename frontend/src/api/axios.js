import axios from 'axios';
import toast from 'react-hot-toast';

const apiClient = axios.create({
  baseURL: import.meta.env.VITE_API_URL,
  headers: {
    'Content-Type': 'application/json',
    'Accept': 'application/json',
  },
});

// Request interceptor - Add token to all requests
apiClient.interceptors.request.use(
  (config) => {
    const token = localStorage.getItem('token');
    if (token) {
      config.headers.Authorization = `Bearer ${token}`;
    }
    return config;
  },
  (error) => {
    return Promise.reject(error);
  }
);

// Response interceptor - Handle 401 (session/token expired)
apiClient.interceptors.response.use(
  (response) => response,
  (error) => {
    if (error.response?.status === 401) {
      toast.error('Sesi Anda telah berakhir. Silakan login kembali.', { duration: 4000 });
      localStorage.removeItem('token');
      localStorage.removeItem('user');
      // Brief delay so the toast is visible before redirect
      setTimeout(() => {
        window.location.href = '/login';
      }, 800);
    }
    return Promise.reject(error);
  }
);

export default apiClient;
