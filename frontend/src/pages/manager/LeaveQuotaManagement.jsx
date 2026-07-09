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
      <div className="space-y-6 sm:space-y-8">
        {/* Header */}
        <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 mb-8">
          <div>
            <h1 className="text-display-md sm:text-display-lg">Jatah Cuti Tahunan</h1>
            <p className="mt-2 font-serif text-body">Kelola jatah cuti per tahun untuk setiap anggota tim</p>
          </div>
          <Button
            onClick={() => {
              setBulkQuotaDays('12');
              setShowBulkModal(true);
            }}
            className="flex-shrink-0"
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
              className="p-2 rounded-full text-muted hover:text-ink transition-colors"
            >
              <ChevronLeft size={24} />
            </button>
            <div className="text-center">
              <p className="caption-uppercase">Tahun</p>
              <p className="font-display text-display-md text-ink">{selectedYear}</p>
            </div>
            <button
              onClick={() => setSelectedYear((y) => y + 1)}
              className="p-2 rounded-full text-muted hover:text-ink transition-colors"
            >
              <ChevronRight size={24} />
            </button>
          </div>
        </Card>

        {/* Info Banner */}
        <div className="rounded-card border-2 border-ink bg-surface-soft p-4 shadow-brutal-sm flex items-start gap-3">
          <Info className="w-5 h-5 text-muted mt-0.5 flex-shrink-0" />
          <div>
            <p className="caption-uppercase">Informasi Jatah Cuti Tahunan</p>
            <p className="mt-2 font-serif text-sm text-body">
              Jatah cuti bersifat <strong className="font-normal text-body-strong">hangus</strong> jika tidak digunakan dalam tahun tersebut.
              Pengguna tanpa jatah khusus akan menggunakan jatah default dari pengaturan profil mereka.
            </p>
          </div>
        </div>

        {/* Quotas Table */}
        <Card>
          {quotas.length === 0 ? (
            <div className="text-center py-16">
              <CalendarDays size={48} className="mx-auto text-muted mb-4" />
              <p className="caption-uppercase">Tidak ada anggota tim ditemukan</p>
            </div>
          ) : (
            <div className="overflow-x-auto">
              <table className="w-full text-left">
                <thead>
                  <tr className="bg-surface-soft border-b border-hairline">
                    <th className="caption-uppercase text-left px-4 py-3">Nama</th>
                    <th className="caption-uppercase px-4 py-3 text-center">Jatah</th>
                    <th className="caption-uppercase px-4 py-3 text-center">Terpakai</th>
                    <th className="caption-uppercase px-4 py-3 text-center">Menunggu</th>
                    <th className="caption-uppercase px-4 py-3 text-center">Sisa</th>
                    <th className="caption-uppercase px-4 py-3 text-center">Sumber</th>
                    <th className="caption-uppercase px-4 py-3 text-right">Aksi</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-hairline">
                  {quotas.map((q) => (
                    <tr key={q.user_id} className="hover:bg-surface-soft transition-colors">
                      <td className="px-4 py-3">
                        <div>
                          <p className="font-serif text-body-strong">{q.name}</p>
                          <p className="font-mono text-sm text-muted">{q.email}</p>
                        </div>
                      </td>
                      <td className="px-4 py-3 text-center">
                        <span className="font-mono text-lg text-ink">{q.quota_days}</span>
                        <span className="font-mono text-sm text-muted ml-1">hari</span>
                      </td>
                      <td className="px-4 py-3 text-center">
                        <span className="font-mono text-lg text-success">{q.used_days}</span>
                      </td>
                      <td className="px-4 py-3 text-center">
                        <span className="font-mono text-lg text-warning">{q.pending_days}</span>
                      </td>
                      <td className="px-4 py-3 text-center">
                        <span className={`font-mono text-lg ${q.remaining_days > 0 ? 'text-ink' : 'text-error'}`}>
                          {q.remaining_days}
                        </span>
                      </td>
                      <td className="px-4 py-3 text-center">
                        {q.is_custom ? (
                          <span className="badge badge-info">
                            Khusus
                          </span>
                        ) : (
                          <span className="badge badge-muted">
                            Default
                          </span>
                        )}
                      </td>
                      <td className="px-4 py-3 text-right">
                        <button
                          onClick={() => handleEdit(q)}
                          className="inline-flex items-center gap-1 font-mono text-caption uppercase text-muted hover:text-ink transition-colors"
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
            <div className="mb-6">
              <p className="font-serif text-sm text-body">
                <strong className="font-normal text-body-strong">Karyawan:</strong> {editingUser.name}
              </p>
              <p className="font-serif text-sm text-body">
                <strong className="font-normal text-body-strong">Tahun:</strong> {selectedYear}
              </p>
            </div>

            <div className="mb-6">
              <label className="caption-uppercase block mb-2">
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

            <div className="flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
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
          <div className="mb-6 rounded-card border-2 border-warning bg-warning/10 p-4 shadow-brutal-sm flex items-start gap-3">
            <Info className="w-5 h-5 text-warning mt-0.5 flex-shrink-0" />
            <div>
              <p className="font-mono text-caption uppercase text-warning">Perhatian</p>
              <p className="mt-2 font-serif text-sm text-body">
                Tindakan ini akan mengatur jatah cuti untuk <strong className="font-normal text-body-strong">semua anggota tim</strong> pada tahun <strong className="font-normal text-body-strong">{selectedYear}</strong>.
                Jatah yang sudah diatur sebelumnya akan ditimpa.
              </p>
            </div>
          </div>

          <div className="mb-6">
            <label className="caption-uppercase block mb-2">
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

          <div className="flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
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
