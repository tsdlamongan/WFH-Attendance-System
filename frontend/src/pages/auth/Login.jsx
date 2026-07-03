import { useState, useEffect } from 'react';
import { useNavigate, useLocation, Link } from 'react-router-dom';
import { useAuth } from '../../hooks/useAuth';
import { usePageTitle } from '../../hooks/usePageTitle';
import { useRegistrationStatus } from '../../hooks/useRegistrationStatus';
import { Input } from '../../components/common/Input';
import { Button } from '../../components/common/Button';
import { LogIn } from 'lucide-react';
import ReCAPTCHA from 'react-google-recaptcha';

const IS_E2E = import.meta.env.VITE_E2E_TEST === 'true';

export const Login = () => {
  usePageTitle('Masuk');

  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [loading, setLoading] = useState(false);
  const [errors, setErrors] = useState({});
  const [captchaToken, setCaptchaToken] = useState(IS_E2E ? 'e2e-test-token' : null);
  const { isEnabled: isRegistrationEnabled, loading: registrationLoading } = useRegistrationStatus();

  const { login, isAuthenticated } = useAuth();
  const navigate = useNavigate();
  const location = useLocation();

  // Redirect if already authenticated
  useEffect(() => {
    if (isAuthenticated) {
      const from = location.state?.from || '/';
      navigate(from, { replace: true });
    }
  }, [isAuthenticated, navigate, location]);

  const handleSubmit = async (e) => {
    e.preventDefault();
    setErrors({});
    setLoading(true);

    // Validation
    const newErrors = {};
    if (!email) newErrors.email = 'Email wajib diisi';
    if (!password) newErrors.password = 'Kata sandi wajib diisi';
    if (!IS_E2E && !captchaToken) newErrors.captcha = 'Silakan verifikasi bahwa Anda bukan robot';

    if (Object.keys(newErrors).length > 0) {
      setErrors(newErrors);
      setLoading(false);
      return;
    }

    try {
      console.log('Attempting login with:', { email, hasCaptchaToken: !!captchaToken });
      const result = await login(email, password, captchaToken);
      
      if (result.success) {
        // Get intended destination from location state, or use default based on role
        const from = location.state?.from;
        
        if (from) {
          // Redirect to intended destination
          navigate(from, { replace: true });
        } else {
          // Default redirect based on role
          if (result.user.role === 'manager') {
            navigate('/manager/dashboard', { replace: true });
          } else {
            navigate('/employee/dashboard', { replace: true });
          }
        }
      }
    } catch (error) {
      console.error('Login error:', error);
    } finally {
      setLoading(false);
    }
  };

  const handleCaptchaChange = (token) => {
    console.log('reCAPTCHA token received:', token ? `${token.substring(0, 20)}...` : 'null');
    setCaptchaToken(token);
    // Clear captcha error when user successfully verifies
    if (token && errors.captcha) {
      setErrors(prev => ({ ...prev, captcha: undefined }));
    }
  };

  return (
    <div className="min-h-screen flex items-center justify-center bg-canvas py-12">
      <div className="max-w-md w-full mx-4">
        <div className="bg-surface-card border border-hairline rounded-none p-6 sm:p-10">
          {/* Header */}
          <div className="text-center mb-10">
            <div className="inline-flex items-center justify-center w-16 h-16 border border-ink rounded-full mb-6">
              <LogIn size={24} className="text-ink" />
            </div>
            <h1 className="text-display-md mb-3">
              WFH
            </h1>
            <p className="font-serif text-body">Masuk ke akun Anda</p>
            <Link
              to="/"
              className="inline-flex items-center font-mono text-caption uppercase text-muted hover:text-ink mt-4 transition-colors"
            >
              ← Kembali ke Beranda
            </Link>
          </div>

          {/* Form */}
          <form onSubmit={handleSubmit} className="space-y-6">
            <Input
              label="Email"
              type="email"
              value={email}
              onChange={(e) => setEmail(e.target.value)}
              error={errors.email}
              placeholder="karyawan@example.com"
              required
            />

            <Input
              label="Kata Sandi"
              type="password"
              value={password}
              onChange={(e) => setPassword(e.target.value)}
              error={errors.password}
              placeholder="Masukkan kata sandi Anda"
              required
            />

            {!IS_E2E && (
              <div className="flex justify-center mb-4">
                <ReCAPTCHA
                  sitekey={import.meta.env.VITE_RECAPTCHA_SITE_KEY}
                  onChange={handleCaptchaChange}
                  theme="dark"
                />
              </div>
            )}

            {errors.captcha && (
              <p className="text-error font-serif text-sm mt-1 mb-4 text-center">{errors.captcha}</p>
            )}

            <Button
              type="submit"
              disabled={loading}
              className="w-full"
            >
              {loading ? 'Masuk...' : 'Masuk'}
            </Button>

            {!registrationLoading && isRegistrationEnabled && (
              <p className="text-center font-serif text-sm text-body mt-4">
                Belum punya akun?{' '}
                <Link to="/register" className="inline-link">
                  Daftar sebagai Manager
                </Link>
              </p>
            )}

            {!registrationLoading && !isRegistrationEnabled && (
              <p className="text-center font-serif text-sm text-muted mt-4">
                Pendaftaran akun baru sedang dinonaktifkan
              </p>
            )}
          </form>
        </div>
      </div>
    </div>
  );
};
