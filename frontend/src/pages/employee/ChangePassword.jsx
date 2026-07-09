import { useState } from 'react';
import { MainLayout } from '../../components/layout/MainLayout';
import { Card } from '../../components/common/Card';
import { Button } from '../../components/common/Button';
import { Input } from '../../components/common/Input';
import { changePassword } from '../../api/user.api';
import { usePageTitle } from '../../hooks/usePageTitle';
import { Lock, Eye, EyeOff, CheckCircle } from 'lucide-react';
import toast from 'react-hot-toast';

export const ChangePassword = () => {
  usePageTitle('Ganti Password');

  const [formData, setFormData] = useState({
    currentPassword: '',
    newPassword: '',
    newPasswordConfirmation: '',
  });
  const [loading, setLoading] = useState(false);
  const [showCurrentPassword, setShowCurrentPassword] = useState(false);
  const [showNewPassword, setShowNewPassword] = useState(false);
  const [showConfirmPassword, setShowConfirmPassword] = useState(false);
  const [errors, setErrors] = useState({});

  const handleChange = (e) => {
    const { name, value } = e.target;
    setFormData(prev => ({
      ...prev,
      [name]: value
    }));
    // Clear error for this field
    if (errors[name]) {
      setErrors(prev => ({
        ...prev,
        [name]: null
      }));
    }
  };

  const validateForm = () => {
    const newErrors = {};

    if (!formData.currentPassword) {
      newErrors.currentPassword = 'Password lama wajib diisi';
    }

    if (!formData.newPassword) {
      newErrors.newPassword = 'Password baru wajib diisi';
    } else if (formData.newPassword.length < 8) {
      newErrors.newPassword = 'Password baru minimal 8 karakter';
    }

    if (!formData.newPasswordConfirmation) {
      newErrors.newPasswordConfirmation = 'Konfirmasi password wajib diisi';
    } else if (formData.newPassword !== formData.newPasswordConfirmation) {
      newErrors.newPasswordConfirmation = 'Konfirmasi password tidak cocok';
    }

    setErrors(newErrors);
    return Object.keys(newErrors).length === 0;
  };

  const handleSubmit = async (e) => {
    e.preventDefault();

    if (!validateForm()) {
      return;
    }

    try {
      setLoading(true);
      const response = await changePassword(
        formData.currentPassword,
        formData.newPassword,
        formData.newPasswordConfirmation
      );

      if (response.success) {
        toast.success(response.message || 'Password berhasil diubah');
        // Reset form
        setFormData({
          currentPassword: '',
          newPassword: '',
          newPasswordConfirmation: '',
        });
      }
    } catch (error) {
      console.error('Change password error:', error);
      
      // Handle validation errors from backend
      if (error.response?.data?.errors) {
        setErrors(error.response.data.errors);
      }
      
      const message = error.response?.data?.message || 'Gagal mengubah password';
      toast.error(message);
    } finally {
      setLoading(false);
    }
  };

  return (
    <MainLayout>
      <div className="max-w-2xl mx-auto">
        <div className="mb-8">
          <h1 className="text-display-md sm:text-display-lg">Ganti Password</h1>
          <p className="mt-2 font-serif text-body">
            Ubah password Anda untuk keamanan akun
          </p>
        </div>

        <Card>
          <form onSubmit={handleSubmit} className="space-y-6">
            {/* Current Password */}
            <div>
              <label className="caption-uppercase block mb-2">
                Password Lama <span className="text-error">*</span>
              </label>
              <div className="relative">
                <div className="absolute inset-y-0 left-3 flex items-center pointer-events-none z-10">
                  <Lock className="h-5 w-5 text-muted" />
                </div>
                <Input
                  type={showCurrentPassword ? 'text' : 'password'}
                  name="currentPassword"
                  value={formData.currentPassword}
                  onChange={handleChange}
                  placeholder="Masukkan password lama"
                  className="input-field pl-11 pr-11"
                  disabled={loading}
                />
                <button
                  type="button"
                  onClick={() => setShowCurrentPassword(!showCurrentPassword)}
                  className="absolute inset-y-0 right-3 flex items-center text-muted hover:text-ink transition-colors z-10"
                >
                  {showCurrentPassword ? (
                    <EyeOff className="h-5 w-5" />
                  ) : (
                    <Eye className="h-5 w-5" />
                  )}
                </button>
              </div>
              {errors.currentPassword && (
                <p className="mt-2 font-serif text-sm text-error">{errors.currentPassword}</p>
              )}
            </div>

            {/* New Password */}
            <div>
              <label className="caption-uppercase block mb-2">
                Password Baru <span className="text-error">*</span>
              </label>
              <div className="relative">
                <div className="absolute inset-y-0 left-3 flex items-center pointer-events-none z-10">
                  <Lock className="h-5 w-5 text-muted" />
                </div>
                <Input
                  type={showNewPassword ? 'text' : 'password'}
                  name="newPassword"
                  value={formData.newPassword}
                  onChange={handleChange}
                  placeholder="Masukkan password baru (min. 8 karakter)"
                  className="input-field pl-11 pr-11"
                  disabled={loading}
                />
                <button
                  type="button"
                  onClick={() => setShowNewPassword(!showNewPassword)}
                  className="absolute inset-y-0 right-3 flex items-center text-muted hover:text-ink transition-colors z-10"
                >
                  {showNewPassword ? (
                    <EyeOff className="h-5 w-5" />
                  ) : (
                    <Eye className="h-5 w-5" />
                  )}
                </button>
              </div>
              {errors.newPassword && (
                <p className="mt-2 font-serif text-sm text-error">{errors.newPassword}</p>
              )}
              <p className="mt-2 font-serif text-sm text-muted">
                Password harus minimal 8 karakter
              </p>
            </div>

            {/* Confirm New Password */}
            <div>
              <label className="caption-uppercase block mb-2">
                Ulangi Password Baru <span className="text-error">*</span>
              </label>
              <div className="relative">
                <div className="absolute inset-y-0 left-3 flex items-center pointer-events-none z-10">
                  <Lock className="h-5 w-5 text-muted" />
                </div>
                <Input
                  type={showConfirmPassword ? 'text' : 'password'}
                  name="newPasswordConfirmation"
                  value={formData.newPasswordConfirmation}
                  onChange={handleChange}
                  placeholder="Ulangi password baru"
                  className="input-field pl-11 pr-11"
                  disabled={loading}
                />
                <button
                  type="button"
                  onClick={() => setShowConfirmPassword(!showConfirmPassword)}
                  className="absolute inset-y-0 right-3 flex items-center text-muted hover:text-ink transition-colors z-10"
                >
                  {showConfirmPassword ? (
                    <EyeOff className="h-5 w-5" />
                  ) : (
                    <Eye className="h-5 w-5" />
                  )}
                </button>
              </div>
              {errors.newPasswordConfirmation && (
                <p className="mt-2 font-serif text-sm text-error">{errors.newPasswordConfirmation}</p>
              )}
            </div>

            {/* Password Requirements Info */}
            <div className="border-2 border-ink bg-surface-soft rounded-card p-4">
              <div className="flex items-start space-x-3">
                <CheckCircle className="h-5 w-5 text-muted mt-0.5 flex-shrink-0" />
                <div className="font-serif text-sm text-body">
                  <p className="caption-uppercase mb-2">Persyaratan Password:</p>
                  <ul className="list-disc list-inside space-y-1">
                    <li>Minimal 8 karakter</li>
                    <li>Gunakan kombinasi huruf dan angka untuk keamanan lebih baik</li>
                    <li>Jangan gunakan password yang mudah ditebak</li>
                  </ul>
                </div>
              </div>
            </div>

            {/* Submit Button */}
            <div className="flex justify-end space-x-3 pt-4">
              <Button
                type="submit"
                disabled={loading}
              >
                {loading ? 'Menyimpan...' : 'Ubah Password'}
              </Button>
            </div>
          </form>
        </Card>
      </div>
    </MainLayout>
  );
};
