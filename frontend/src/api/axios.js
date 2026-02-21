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
      const isImpersonating =
        sessionStorage.getItem('is_impersonating') === 'true' ||
        localStorage.getItem('is_impersonating') === 'true';

      if (isImpersonating) {
        toast.error('Sesi impersonasi telah berakhir. Silakan login kembali sebagai Super Admin.', {
          duration: 5000,
        });
        sessionStorage.removeItem('original_user_id');
        sessionStorage.removeItem('is_impersonating');
        localStorage.removeItem('original_user_id');
        localStorage.removeItem('is_impersonating');
      } else {
        toast.error('Sesi Anda telah berakhir. Silakan login kembali.', { duration: 4000 });
      }

      localStorage.removeItem('token');
      localStorage.removeItem('user');

      setTimeout(() => {
        window.location.href = '/login';
      }, 800);
    }
    return Promise.reject(error);
  }
);

export default apiClient;
