import { createContext, useState, useEffect } from 'react';
import { login as loginApi, logout as logoutApi } from '../api/auth.api';
import toast from 'react-hot-toast';

// eslint-disable-next-line react-refresh/only-export-components -- context object is exported next to its provider on purpose
export const AuthContext = createContext(null);

export const AuthProvider = ({ children }) => {
  const [user, setUser] = useState(null);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    // Check if user is already logged in
    const storedUser = localStorage.getItem('user');
    const token = localStorage.getItem('token');
    
    if (storedUser && token) {
      setUser(JSON.parse(storedUser));
    }
    setLoading(false);
  }, []);

  const setSession = (userData, token) => {
    setUser(userData);
    localStorage.setItem('user', JSON.stringify(userData));
    if (token) {
      localStorage.setItem('token', token);
    }
  };

  const login = async (email, password, captchaToken) => {
    try {
      const response = await loginApi(email, password, captchaToken);
      
      if (response.success) {
        const { user: userData, token } = response.data;
        
        setSession(userData, token);
        
        toast.success(response.message || 'Login berhasil');
        return { success: true, user: userData };
      }
    } catch (error) {
      const message = error.response?.data?.message || 'Login gagal';
      toast.error(message);
      throw error;
    }
  };

  const logout = async () => {
    try {
      await logoutApi();
    } catch (error) {
      console.error('Logout error:', error);
    } finally {
      setUser(null);
      localStorage.removeItem('user');
      localStorage.removeItem('token');
      toast.success('Logout berhasil');
    }
  };

  const value = {
    user,
    login,
    logout,
    setSession,
    loading,
    isAuthenticated: !!user,
    isEmployee: user?.role === 'employee',
    isManager: user?.role === 'manager',
    isSuperAdmin: user?.role === 'super_admin',
  };

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>;
};
