import { useState } from 'react';
import { useNavigate, Link } from 'react-router-dom';
import { Button } from '../../components/common/Button';
import { Input } from '../../components/common/Input';
import { useAuth } from '../../hooks/useAuth';
import { register } from '../../api/auth.api';
import { Building2, Users, Clock, Calendar } from 'lucide-react';
import toast from 'react-hot-toast';
import ReCAPTCHA from 'react-google-recaptcha';

const IS_E2E = import.meta.env.VITE_E2E_TEST === 'true';

export const Register = () => {
  const navigate = useNavigate();
  const { setSession } = useAuth();
  const [loading, setLoading] = useState(false);
  const [captchaToken, setCaptchaToken] = useState(IS_E2E ? 'e2e-test-token' : null);
  const [errors, setErrors] = useState({});
  const [formData, setFormData] = useState({
    name: '',
    email: '',
    password: '',
    password_confirmation: '',
    team_name: '',
    team_description: '',
    required_work_hours: '7',
    default_leave_quota_days: '12',
    max_leave_days_per_month: '5',
  });

  const handleSubmit = async (e) => {
    e.preventDefault();
    setErrors({});

    // Validation
    const newErrors = {};
    if (!IS_E2E && !captchaToken) {
      newErrors.captcha = 'Silakan verifikasi bahwa Anda bukan robot';
    }

    if (Object.keys(newErrors).length > 0) {
      setErrors(newErrors);
      return;
    }

    if (formData.password !== formData.password_confirmation) {
      toast.error('Password dan konfirmasi password tidak cocok');
      return;
    }

    if (formData.password.length < 8) {
      toast.error('Password minimal 8 karakter');
      return;
    }

    try {
      setLoading(true);
      const response = await register({ ...formData, captcha_token: captchaToken });

      if (response.success) {
        setSession(response.data.user, response.data.token);

        toast.success(response.message || 'Registrasi berhasil!');
        navigate('/manager/dashboard');
      }
    } catch (error) {
      const message = error.response?.data?.message || 'Registrasi gagal. Silakan coba lagi.';
      toast.error(message);
    } finally {
      setLoading(false);
    }
  };

  const handleCaptchaChange = (token) => {
    setCaptchaToken(token);
    // Clear captcha error when user successfully verifies
    if (token && errors.captcha) {
      setErrors(prev => ({ ...prev, captcha: undefined }));
    }
  };

  return (
    <div className="min-h-screen bg-gradient-to-br from-primary-50 via-white to-primary-50 flex items-center justify-center p-4">
      <div className="max-w-2xl w-full">
        <div className="text-center mb-8">
          <div className="inline-flex items-center justify-center w-16 h-16 bg-primary-600 rounded-2xl mb-4">
            <Building2 size={32} className="text-white" />
          </div>
          <h1 className="text-3xl font-bold text-gray-900 mb-2">
            Daftar Tim Baru
          </h1>
          <p className="text-gray-600 mb-2">
            Buat tim Anda dan mulai mengelola kehadiran karyawan
          </p>
          <Link
            to="/"
            className="inline-flex items-center text-sm text-blue-600 hover:text-blue-700 transition-colors"
          >
            ← Kembali ke Beranda
          </Link>
        </div>

        <div className="bg-white rounded-2xl shadow-xl p-8">
          <form onSubmit={handleSubmit} className="space-y-6">
            {/* Manager Info Section */}
            <div>
              <h2 className="text-lg font-semibold text-gray-900 mb-4 flex items-center">
                <Users size={20} className="mr-2" />
                Informasi Manager
              </h2>
              <div className="space-y-4">
                <Input
                  label="Nama Lengkap"
                  value={formData.name}
                  onChange={(e) => setFormData({ ...formData, name: e.target.value })}
                  required
                  placeholder="Masukkan nama lengkap Anda"
                />

                <Input
                  label="Email"
                  type="email"
                  value={formData.email}
                  onChange={(e) => setFormData({ ...formData, email: e.target.value })}
                  required
                  placeholder="manager@perusahaan.com"
                />

                <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                  <Input
                    label="Password"
                    type="password"
                    value={formData.password}
                    onChange={(e) => setFormData({ ...formData, password: e.target.value })}
                    required
                    placeholder="Minimal 8 karakter"
                  />

                  <Input
                    label="Konfirmasi Password"
                    type="password"
                    value={formData.password_confirmation}
                    onChange={(e) => setFormData({ ...formData, password_confirmation: e.target.value })}
                    required
                    placeholder="Ulangi password"
                  />
                </div>
              </div>
            </div>

            {/* Team Info Section */}
            <div className="border-t pt-6">
              <h2 className="text-lg font-semibold text-gray-900 mb-4 flex items-center">
                <Building2 size={20} className="mr-2" />
                Informasi Tim
              </h2>
              <div className="space-y-4">
                <Input
                  label="Nama Tim"
                  value={formData.team_name}
                  onChange={(e) => setFormData({ ...formData, team_name: e.target.value })}
                  required
                  placeholder="PT. Nama Perusahaan"
                />

                <div className="mb-4">
                  <label className="block text-sm font-medium text-gray-700 mb-2">
                    Deskripsi Tim (Opsional)
                  </label>
                  <textarea
                    value={formData.team_description}
                    onChange={(e) => setFormData({ ...formData, team_description: e.target.value })}
                    className="input-field"
                    rows="3"
                    placeholder="Deskripsi singkat tentang tim Anda..."
                  />
                </div>
              </div>
            </div>

            {/* Team Settings Section */}
            <div className="border-t pt-6">
              <h2 className="text-lg font-semibold text-gray-900 mb-4 flex items-center">
                <Clock size={20} className="mr-2" />
                Pengaturan Tim
              </h2>
              <div className="space-y-4">
                <Input
                  label="Jam Kerja Wajib per Hari"
                  type="number"
                  step="0.5"
                  min="1"
                  max="24"
                  value={formData.required_work_hours}
                  onChange={(e) => setFormData({ ...formData, required_work_hours: e.target.value })}
                  required
                  helperText="Jumlah jam kerja yang harus dipenuhi karyawan per hari"
                />

                <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                  <Input
                    label="Jatah Cuti Tahunan"
                    type="number"
                    min="0"
                    max="365"
                    value={formData.default_leave_quota_days}
                    onChange={(e) => setFormData({ ...formData, default_leave_quota_days: e.target.value })}
                    required
                    helperText="Jumlah hari cuti per tahun"
                  />

                  <Input
                    label="Maksimal Cuti per Bulan"
                    type="number"
                    min="0"
                    max="31"
                    value={formData.max_leave_days_per_month}
                    onChange={(e) => setFormData({ ...formData, max_leave_days_per_month: e.target.value })}
                    required
                    helperText="Batas cuti yang bisa diambil per bulan"
                  />
                </div>
              </div>
            </div>

            {!IS_E2E && (
              <div className="flex justify-center pt-6">
                <ReCAPTCHA
                  sitekey={import.meta.env.VITE_RECAPTCHA_SITE_KEY}
                  onChange={handleCaptchaChange}
                />
              </div>
            )}

            {errors.captcha && (
              <p className="text-red-500 text-sm mt-2 text-center">{errors.captcha}</p>
            )}

            <div className="flex flex-col space-y-4 pt-6">
              <Button
                type="submit"
                disabled={loading}
                className="w-full"
              >
                {loading ? 'Mendaftar...' : 'Daftar Sekarang'}
              </Button>

              <p className="text-center text-sm text-gray-600">
                Sudah punya akun?{' '}
                <Link to="/login" className="text-primary-600 hover:text-primary-700 font-medium">
                  Login di sini
                </Link>
              </p>
            </div>
          </form>
        </div>

        <p className="text-center text-sm text-gray-500 mt-6">
          Dengan mendaftar, Anda menyetujui syarat dan ketentuan kami
        </p>
      </div>
    </div>
  );
};
