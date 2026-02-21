import { useState, useEffect } from 'react';
import { MainLayout } from '../../components/layout/MainLayout';
import { Card } from '../../components/common/Card';
import { Button } from '../../components/common/Button';
import { Loading } from '../../components/common/Loading';
import { Modal } from '../../components/common/Modal';
import { getLeaveQuotas, updateLeaveQuota, bulkUpdateLeaveQuotas } from '../../api/manager.api';
import { usePageTitle } from '../../hooks/usePageTitle';
import { CalendarDays, Edit3, Users, ChevronLeft, ChevronRight, Info } from 'lucide-react';
import toast from 'react-hot-toast';

export const LeaveQuotaManagement = () => {
  usePageTitle('Jatah Cuti Tahunan');
  const currentYear = new Date().getFullYear();
  const [selectedYear, setSelectedYear] = useState(currentYear);
  const [loading, setLoading] = useState(true);
  const [quotas, setQuotas] = useState([]);

  const [showEditModal, setShowEditModal] = useState(false);
  const [editingUser, setEditingUser] = useState(null);
  const [editQuotaDays, setEditQuotaDays] = useState('');
  const [submitting, setSubmitting] = useState(false);

  const [showBulkModal, setShowBulkModal] = useState(false);
  const [bulkQuotaDays, setBulkQuotaDays] = useState('12');

  useEffect(() => {
    fetchQuotas();
  }, [selectedYear]);

  const fetchQuotas = async () => {
    try {
      setLoading(true);
      const response = await getLeaveQuotas(selectedYear);
      if (response.success) {
        setQuotas(response.data);
      }
    } catch (error) {
      console.error('Error fetching leave quotas:', error);
      toast.error('Gagal mengambil data jatah cuti');
    } finally {
      setLoading(false);
    }
  };

  const handleEdit = (user) => {
    setEditingUser(user);
    setEditQuotaDays(String(user.quota_days));
    setShowEditModal(true);
  };

  const handleEditSubmit = async (e) => {
    e.preventDefault();
    const days = parseInt(editQuotaDays, 10);
    if (isNaN(days) || days < 0 || days > 365) {
      toast.error('Jumlah hari harus antara 0 - 365');
      return;
    }

    try {
      setSubmitting(true);
      const response = await updateLeaveQuota(editingUser.user_id, selectedYear, days);
      if (response.success) {
        toast.success(response.message);
        setShowEditModal(false);
        setEditingUser(null);
        fetchQuotas();
      }
    } catch (error) {
      const message = error.response?.data?.message || 'Gagal memperbarui jatah cuti';
      toast.error(message);
    } finally {
      setSubmitting(false);
    }
  };

  const handleBulkSubmit = async (e) => {
    e.preventDefault();
    const days = parseInt(bulkQuotaDays, 10);
    if (isNaN(days) || days < 0 || days > 365) {
      toast.error('Jumlah hari harus antara 0 - 365');
      return;
    }

    try {
      setSubmitting(true);
      const response = await bulkUpdateLeaveQuotas(selectedYear, days);
      if (response.success) {
        toast.success(response.message);
        setShowBulkModal(false);
        fetchQuotas();
      }
    } catch (error) {
      const message = error.response?.data?.message || 'Gagal memperbarui jatah cuti';
      toast.error(message);
    } finally {
      setSubmitting(false);
    }
  };

  if (loading) {
    return (
      <MainLayout>
        <Loading />
      </MainLayout>
    );
  }

  return (
    <MainLayout>
      <div className="space-y-6">
        {/* Header */}
        <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
          <div>
            <h1 className="text-3xl font-bold text-gray-900">Jatah Cuti Tahunan</h1>
            <p className="text-gray-600 mt-1">Kelola jatah cuti per tahun untuk setiap anggota tim</p>
          </div>
          <Button
            onClick={() => {
              setBulkQuotaDays('12');
              setShowBulkModal(true);
            }}
            className="flex items-center space-x-2"
          >
            <Users size={20} />
            <span>Atur Semua</span>
          </Button>
        </div>

        {/* Year Selector */}
        <Card>
          <div className="flex items-center justify-center space-x-4">
            <button
              onClick={() => setSelectedYear((y) => y - 1)}
              className="p-2 rounded-lg hover:bg-gray-100 transition-colors"
            >
              <ChevronLeft size={24} className="text-gray-600" />
            </button>
            <div className="text-center">
              <p className="text-sm text-gray-500 font-medium">Tahun</p>
              <p className="text-3xl font-bold text-gray-900">{selectedYear}</p>
            </div>
            <button
              onClick={() => setSelectedYear((y) => y + 1)}
              className="p-2 rounded-lg hover:bg-gray-100 transition-colors"
            >
              <ChevronRight size={24} className="text-gray-600" />
            </button>
          </div>
        </Card>

        {/* Info Banner */}
        <div className="p-4 bg-blue-50 border border-blue-200 rounded-lg flex items-start gap-3">
          <Info className="w-5 h-5 text-blue-600 mt-0.5 flex-shrink-0" />
          <div className="text-sm text-blue-800">
            <p className="font-medium">Informasi Jatah Cuti Tahunan</p>
            <p className="mt-1">
              Jatah cuti bersifat <strong>hangus</strong> jika tidak digunakan dalam tahun tersebut.
              Pengguna tanpa jatah khusus akan menggunakan jatah default dari pengaturan profil mereka.
            </p>
          </div>
        </div>

        {/* Quotas Table */}
        <Card>
          {quotas.length === 0 ? (
            <div className="text-center py-12">
              <CalendarDays size={48} className="mx-auto text-gray-400 mb-4" />
              <p className="text-gray-600">Tidak ada anggota tim ditemukan</p>
            </div>
          ) : (
            <div className="overflow-x-auto">
              <table className="w-full text-left">
                <thead>
                  <tr className="border-b border-gray-200">
                    <th className="pb-3 text-sm font-semibold text-gray-600">Nama</th>
                    <th className="pb-3 text-sm font-semibold text-gray-600 text-center">Jatah</th>
                    <th className="pb-3 text-sm font-semibold text-gray-600 text-center">Terpakai</th>
                    <th className="pb-3 text-sm font-semibold text-gray-600 text-center">Menunggu</th>
                    <th className="pb-3 text-sm font-semibold text-gray-600 text-center">Sisa</th>
                    <th className="pb-3 text-sm font-semibold text-gray-600 text-center">Sumber</th>
                    <th className="pb-3 text-sm font-semibold text-gray-600 text-right">Aksi</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-gray-100">
                  {quotas.map((q) => (
                    <tr key={q.user_id} className="hover:bg-gray-50 transition-colors">
                      <td className="py-3">
                        <div>
                          <p className="font-medium text-gray-900">{q.name}</p>
                          <p className="text-sm text-gray-500">{q.email}</p>
                        </div>
                      </td>
                      <td className="py-3 text-center">
                        <span className="text-lg font-bold text-blue-700">{q.quota_days}</span>
                        <span className="text-sm text-gray-500 ml-1">hari</span>
                      </td>
                      <td className="py-3 text-center">
                        <span className="text-lg font-semibold text-green-700">{q.used_days}</span>
                      </td>
                      <td className="py-3 text-center">
                        <span className="text-lg font-semibold text-yellow-600">{q.pending_days}</span>
                      </td>
                      <td className="py-3 text-center">
                        <span className={`text-lg font-bold ${q.remaining_days > 0 ? 'text-purple-700' : 'text-red-600'}`}>
                          {q.remaining_days}
                        </span>
                      </td>
                      <td className="py-3 text-center">
                        {q.is_custom ? (
                          <span className="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-blue-100 text-blue-800">
                            Khusus
                          </span>
                        ) : (
                          <span className="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-600">
                            Default
                          </span>
                        )}
                      </td>
                      <td className="py-3 text-right">
                        <button
                          onClick={() => handleEdit(q)}
                          className="inline-flex items-center space-x-1 text-sm text-primary-600 hover:text-primary-800 font-medium transition-colors"
                        >
                          <Edit3 size={16} />
                          <span>Edit</span>
                        </button>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </Card>
      </div>

      {/* Edit Individual Quota Modal */}
      <Modal
        isOpen={showEditModal}
        onClose={() => setShowEditModal(false)}
        title="Edit Jatah Cuti"
        size="sm"
      >
        {editingUser && (
          <form onSubmit={handleEditSubmit}>
            <div className="mb-4">
              <p className="text-sm text-gray-600">
                <strong>Karyawan:</strong> {editingUser.name}
              </p>
              <p className="text-sm text-gray-600">
                <strong>Tahun:</strong> {selectedYear}
              </p>
            </div>

            <div className="mb-4">
              <label className="block text-sm font-medium text-gray-700 mb-2">
                Jumlah Jatah Cuti (hari)
              </label>
              <input
                type="number"
                value={editQuotaDays}
                onChange={(e) => setEditQuotaDays(e.target.value)}
                className="input-field"
                min="0"
                max="365"
                required
              />
            </div>

            <div className="flex justify-end space-x-3">
              <Button
                type="button"
                variant="secondary"
                onClick={() => setShowEditModal(false)}
              >
                Batal
              </Button>
              <Button type="submit" disabled={submitting}>
                {submitting ? 'Menyimpan...' : 'Simpan'}
              </Button>
            </div>
          </form>
        )}
      </Modal>

      {/* Bulk Update Modal */}
      <Modal
        isOpen={showBulkModal}
        onClose={() => setShowBulkModal(false)}
        title="Atur Jatah Cuti Semua Anggota"
        size="sm"
      >
        <form onSubmit={handleBulkSubmit}>
          <div className="mb-4 p-4 bg-yellow-50 border border-yellow-200 rounded-lg flex items-start gap-3">
            <Info className="w-5 h-5 text-yellow-600 mt-0.5 flex-shrink-0" />
            <div className="text-sm text-yellow-800">
              <p className="font-medium">Perhatian</p>
              <p className="mt-1">
                Tindakan ini akan mengatur jatah cuti untuk <strong>semua anggota tim</strong> pada tahun <strong>{selectedYear}</strong>.
                Jatah yang sudah diatur sebelumnya akan ditimpa.
              </p>
            </div>
          </div>

          <div className="mb-4">
            <label className="block text-sm font-medium text-gray-700 mb-2">
              Jumlah Jatah Cuti (hari)
            </label>
            <input
              type="number"
              value={bulkQuotaDays}
              onChange={(e) => setBulkQuotaDays(e.target.value)}
              className="input-field"
              min="0"
              max="365"
              required
            />
          </div>

          <div className="flex justify-end space-x-3">
            <Button
              type="button"
              variant="secondary"
              onClick={() => setShowBulkModal(false)}
            >
              Batal
            </Button>
            <Button type="submit" disabled={submitting}>
              {submitting ? 'Menyimpan...' : 'Terapkan ke Semua'}
            </Button>
          </div>
        </form>
      </Modal>
    </MainLayout>
  );
};
