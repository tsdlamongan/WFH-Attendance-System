import { Navigate } from 'react-router-dom';
import { useRegistrationStatus } from '../../hooks/useRegistrationStatus';
import { Loading } from './Loading';

export const ProtectedRegisterRoute = ({ children }) => {
  const { isEnabled, loading } = useRegistrationStatus();

  if (loading) {
    return <Loading fullScreen />;
  }

  if (!isEnabled) {
    return <Navigate to="/login" replace />;
  }

  return children;
};