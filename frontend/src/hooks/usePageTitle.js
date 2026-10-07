import { useEffect } from 'react';
import { useLocation } from 'react-router-dom';
import { APP_NAME } from '../utils/constants';

// Page titles mapping
const pageTitles = {
  '/login': 'Masuk',
  '/employee/dashboard': 'Dashboard',
  '/employee/report': 'Laporan Saya',
  '/employee/leave': 'Pengajuan Cuti',
  '/employee/overtime': 'Pengajuan Lembur',
  '/manager/dashboard': 'Dashboard',
  '/manager/users': 'Manajemen Pengguna',
  '/manager/attendances': 'Absensi',
  '/manager/daily-attendance-report': 'Laporan Harian',
  '/manager/monthly-attendance-report': 'Laporan Bulanan',
  '/manager/evaluation-report': 'Laporan Evaluasi',
  '/manager/projects': 'Project',
  '/manager/leaves': 'Persetujuan Cuti',
  '/manager/overtime': 'Persetujuan Lembur',
  '/manager/holidays': 'Hari Libur',
  '/manager/activity-logs': 'Log Aktivitas',
};

export const usePageTitle = (customTitle = null) => {
  const location = useLocation();

  useEffect(() => {
    let title = customTitle || pageTitles[location.pathname] || 'Dashboard';
    
    // Format: "Page Title - App Name"
    document.title = `${title} - ${APP_NAME}`;
    
    // Cleanup function to reset title when component unmounts
    return () => {
      document.title = APP_NAME;
    };
  }, [location.pathname, customTitle]);
};
