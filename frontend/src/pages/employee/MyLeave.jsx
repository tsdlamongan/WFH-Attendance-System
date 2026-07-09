import { useState, useEffect } from 'react';
import { MainLayout } from '../../components/layout/MainLayout';
import { Card } from '../../components/common/Card';
import { Button } from '../../components/common/Button';
import { Input } from '../../components/common/Input';
import { Loading } from '../../components/common/Loading';
import { Modal } from '../../components/common/Modal';
import { requestLeave, getMyLeaveRequests, getLeaveSummary } from '../../api/leave.api';
import { formatDate } from '../../utils/dateHelpers';
import { usePageTitle } from '../../hooks/usePageTitle';
import { Calendar, Plus, Clock, CheckCircle, XCircle, Info, ChevronLeft, ChevronRight } from 'lucide-react';
import toast from 'react-hot-toast';

export const MyLeave = () => {
  usePageTitle('Pengajuan Cuti');
  const currentYear = new Date().getFullYear();
  const [selectedYear, setSelectedYear] = useState(currentYear);
  const [loading, setLoading] = useState(true);
  const [leaves, setLeaves] = useState([]);
  const [leaveSummary, setLeaveSummary] = useState(null);
  const [showModal, setShowModal] = useState(false);
  const [submitting, setSubmitting] = useState(false);
  
  const [formData, setFormData] = useState({
    start_date: '',
    end_date: '',
    reason: '',
  });

  useEffect(() => {
    fetchLeaves();
  }, []);

  useEffect(() => {
    fetchLeaveSummary(selectedYear);
  }, [selectedYear]);

  const fetchLeaves = async () => {
    try {
      setLoading(true);
      const response = await getMyLeaveRequests();
      
      if (response.success) {
        setLeaves(response.data);
      }
    } catch (error) {
      console.error('Error fetching leaves:', error);
      toast.error('Gagal mengambil pengajuan cuti');
    } finally {
      setLoading(false);
    }
  };

  const fetchLeaveSummary = async (year) => {
    try {
      const response = await getLeaveSummary(year);
      if (response.success) {
        setLeaveSummary(response.data);
      }
    } catch (error) {
      console.error('Error fetching leave summary:', error);
    }
  };

  const handleSubmit = async (e) => {
    e.preventDefault();
    
    if (formData.reason.length < 10) {
      toast.error('Alasan harus minimal 10 karakter');
      return;
    }

    try {
      setSubmitting(true);
      const response = await requestLeave(
        formData.start_date,
        formData.end_date,
        formData.reason
      );
      
      if (response.success) {
        toast.success(response.message);
        setShowModal(false);
        setFormData({ start_date: '', end_date: '', reason: '' });
        fetchLeaves();
        fetchLeaveSummary(selectedYear);
      }
    } catch (error) {
      const message = error.response?.data?.message || 'Failed to request leave';
      toast.error(message);
    } finally {
      setSubmitting(false);
    }
  };

  const getStatusBadge = (status) => {
    const badges = {
      pending: 'badge-warning',
      approved: 'badge-success',
      rejected: 'badge-danger',
    };
    return badges[status] || 'badge-info';
  };

  const getStatusIcon = (status) => {
    if (status === 'approved') return <CheckCircle size={20} className="text-success" />;
    if (status === 'rejected') return <XCircle size={20} className="text-error" />;
    return <Clock size={20} className="text-warning" />;
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
        <div className="flex flex-wrap items-center justify-between gap-4">
          <div>
            <h1 className="text-display-md sm:text-display-lg">Pengajuan Cuti</h1>
            <p className="mt-2 font-serif text-body">Kelola pengajuan cuti Anda</p>
          </div>
          <Button
            onClick={() => setShowModal(true)}
          >
            <Plus size={20} />
            <span>Ajukan Cuti</span>
          </Button>
        </div>

        {/* Leave Quota Summary */}
        {leaveSummary && (
          <Card>
            <div className="flex items-start space-x-3 mb-4">
              <Info className="text-muted mt-1" size={20} />
              <div className="flex-1">
                <div className="flex flex-wrap items-center justify-between gap-2 mb-4">
                  <h3 className="text-title-md">Informasi Jatah Cuti Tahun {leaveSummary.year}</h3>
                  <div className="flex items-center space-x-2">
                    <button
                      onClick={() => setSelectedYear((y) => y - 1)}
                      className="p-1 text-muted hover:text-ink transition-colors"
                    >
                      <ChevronLeft size={18} />
                    </button>
                    <span className="font-mono text-sm text-ink w-12 text-center">{selectedYear}</span>
                    <button
                      onClick={() => setSelectedYear((y) => y + 1)}
                      className="p-1 text-muted hover:text-ink transition-colors"
                    >
                      <ChevronRight size={18} />
                    </button>
                  </div>
                </div>
                <div className="grid grid-cols-2 md:grid-cols-5 gap-3 sm:gap-4">
                  <div className="border-2 border-ink rounded-xl bg-surface-soft p-3">
                    <p className="caption-uppercase mb-1">Total Jatah</p>
                    <p className="font-display text-display-md text-ink">{leaveSummary.total_quota}</p>
                    <p className="caption-uppercase">hari</p>
                  </div>
                  <div className="border-2 border-ink rounded-xl bg-surface-soft p-3">
                    <p className="caption-uppercase mb-1">Terpakai</p>
                    <p className="font-display text-display-md text-ink">{leaveSummary.used_days}</p>
                    <p className="caption-uppercase">hari</p>
                  </div>
                  <div className="border-2 border-ink rounded-xl bg-surface-soft p-3">
                    <p className="caption-uppercase mb-1">Menunggu</p>
                    <p className="font-display text-display-md text-ink">{leaveSummary.pending_days}</p>
                    <p className="caption-uppercase">hari</p>
                  </div>
                  <div className="border-2 border-ink rounded-xl bg-surface-soft p-3">
                    <p className="caption-uppercase mb-1">Sisa</p>
                    <p className="font-display text-display-md text-ink">{leaveSummary.remaining_days}</p>
                    <p className="caption-uppercase">hari</p>
                  </div>
                  <div className="border-2 border-ink rounded-xl bg-surface-soft p-3">
                    <p className="caption-uppercase mb-1">Max/Bulan</p>
                    <p className="font-display text-display-md text-ink">{leaveSummary.max_per_month}</p>
                    <p className="caption-uppercase">hari</p>
                  </div>
                </div>
              </div>
            </div>
          </Card>
        )}

        {/* Leave Requests List */}
        <Card>
          {leaves.length === 0 ? (
            <div className="text-center py-16">
              <Calendar size={48} className="mx-auto text-muted mb-4" />
              <p className="caption-uppercase">Belum ada pengajuan cuti</p>
              <Button
                onClick={() => setShowModal(true)}
                variant="outline"
                className="mt-4"
              >
                Ajukan Cuti Pertama Anda
              </Button>
            </div>
          ) : (
            <div className="space-y-4">
              {leaves.map((leave) => (
                <div
                  key={leave.id}
                  className="border-2 border-ink rounded-card p-4 hover:shadow-brutal-sm transition-all"
                >
                  <div className="flex items-start justify-between gap-3 mb-3">
                    <div className="flex items-start space-x-3">
                      {getStatusIcon(leave.status)}
                      <div>
                        <p className="font-mono text-sm text-ink">
                          {formatDate(leave.start_date)} - {formatDate(leave.end_date)}
                        </p>
                        <p className="caption-uppercase mt-1">
                          Diajukan pada {formatDate(leave.requested_at)}
                        </p>
                      </div>
                    </div>
                    <span className={`badge ${getStatusBadge(leave.status)}`}>
                      {leave.status === 'pending' ? 'Menunggu' : leave.status === 'approved' ? 'Disetujui' : 'Ditolak'}
                    </span>
                  </div>

                  <div className="bg-surface-soft border-2 border-ink rounded-xl p-3 mb-3">
                    <p className="caption-uppercase mb-1">Alasan:</p>
                    <p className="font-serif text-sm text-body">{leave.reason}</p>
                  </div>

                  {leave.notes && (
                    <div className="bg-surface-soft border-2 border-ink rounded-xl p-3">
                      <p className="caption-uppercase mb-1">Catatan Manager:</p>
                      <p className="font-serif text-sm text-body">{leave.notes}</p>
                    </div>
                  )}
                </div>
              ))}
            </div>
          )}
        </Card>
      </div>

      {/* Request Leave Modal */}
      <Modal
        isOpen={showModal}
        onClose={() => setShowModal(false)}
        title="Ajukan Cuti"
        size="md"
      >
        <form onSubmit={handleSubmit}>
          {/* Info: H-7 Policy */}
          <div className="mb-6 p-4 bg-surface-soft border-2 border-ink rounded-xl flex items-start gap-3">
            <Info className="w-5 h-5 text-muted mt-0.5 flex-shrink-0" />
            <div className="font-serif text-sm text-body">
              <p className="caption-uppercase">Informasi Pengajuan Cuti</p>
              <p className="mt-1">
                Pengajuan cuti sebaiknya diajukan minimal <strong className="font-normal text-ink">7 hari sebelum</strong> tanggal cuti dimulai (H-7) untuk memudahkan pengaturan jadwal kerja tim.
              </p>
            </div>
          </div>

          <Input
            label="Tanggal Mulai"
            type="date"
            value={formData.start_date}
            onChange={(e) => setFormData({ ...formData, start_date: e.target.value })}
            required
            min={new Date().toISOString().split('T')[0]}
          />

          <Input
            label="Tanggal Akhir"
            type="date"
            value={formData.end_date}
            onChange={(e) => setFormData({ ...formData, end_date: e.target.value })}
            required
            min={formData.start_date || new Date().toISOString().split('T')[0]}
          />

          <div className="mb-6">
            <label className="caption-uppercase block mb-2">
              Alasan <span className="text-error">*</span>
            </label>
            <textarea
              value={formData.reason}
              onChange={(e) => setFormData({ ...formData, reason: e.target.value })}
              className="input-field"
              rows="4"
              placeholder="Harap berikan alasan untuk cuti Anda (minimal 10 karakter)"
              required
              minLength={10}
              maxLength={500}
            />
            <p className="caption-uppercase mt-2">
              {formData.reason.length}/500 karakter (minimal 10)
            </p>
          </div>

          <div className="flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
            <Button
              type="button"
              variant="secondary"
              onClick={() => setShowModal(false)}
            >
              Batal
            </Button>
            <Button type="submit" disabled={submitting}>
              {submitting ? 'Mengirim...' : 'Kirim Pengajuan'}
            </Button>
          </div>
        </form>
      </Modal>
    </MainLayout>
  );
};
