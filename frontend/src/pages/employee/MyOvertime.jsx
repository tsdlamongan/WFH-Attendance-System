import { useState, useEffect } from 'react';
import { MainLayout } from '../../components/layout/MainLayout';
import { Card } from '../../components/common/Card';
import { Button } from '../../components/common/Button';
import { Loading } from '../../components/common/Loading';
import { Modal } from '../../components/common/Modal';
import { OvertimeReasonField, OvertimeDurationSelect } from '../../components/overtime/OvertimeFields';
import { requestOvertime, getMyOvertimes } from '../../api/overtime.api';
import { formatDate, formatDateTime, formatHours, formatMinutes } from '../../utils/dateHelpers';
import {
  OVERTIME_STATUS,
  OVERTIME_STATUS_LABELS,
  OVERTIME_STATUS_BADGES,
  OVERTIME_DEFAULT_MINUTES,
  OVERTIME_REASON_MIN_LENGTH,
} from '../../utils/constants';
import { usePageTitle } from '../../hooks/usePageTitle';
import { Timer, Plus, Clock, CheckCircle, XCircle, Info } from 'lucide-react';
import toast from 'react-hot-toast';

const EMPTY_FORM = { reason: '', planned_minutes: OVERTIME_DEFAULT_MINUTES };

export const MyOvertime = () => {
  usePageTitle('Pengajuan Lembur');
  const [loading, setLoading] = useState(true);
  const [overtimes, setOvertimes] = useState([]);
  const [showModal, setShowModal] = useState(false);
  const [submitting, setSubmitting] = useState(false);
  const [formData, setFormData] = useState(EMPTY_FORM);

  useEffect(() => {
    fetchOvertimes();
  }, []);

  const fetchOvertimes = async () => {
    try {
      setLoading(true);
      const response = await getMyOvertimes();

      if (response.success) {
        setOvertimes(response.data);
      }
    } catch (error) {
      console.error('Error fetching overtimes:', error);
      toast.error('Gagal mengambil pengajuan lembur');
    } finally {
      setLoading(false);
    }
  };

  const handleOpenModal = () => {
    setFormData(EMPTY_FORM);
    setShowModal(true);
  };

  const handleSubmit = async (e) => {
    e.preventDefault();

    const reason = formData.reason.trim();
    if (reason.length < OVERTIME_REASON_MIN_LENGTH) {
      toast.error(`Alasan harus minimal ${OVERTIME_REASON_MIN_LENGTH} karakter`);
      return;
    }

    try {
      setSubmitting(true);
      const response = await requestOvertime(reason, formData.planned_minutes);

      if (response.success) {
        toast.success(response.message || 'Pengajuan lembur berhasil dikirim');
        setShowModal(false);
        setFormData(EMPTY_FORM);
        fetchOvertimes();
      }
    } catch (error) {
      const message = error.response?.data?.message || 'Gagal mengajukan lembur';
      toast.error(message);
    } finally {
      setSubmitting(false);
    }
  };

  const getStatusIcon = (status) => {
    if (status === OVERTIME_STATUS.APPROVED) return <CheckCircle size={20} className="text-success" />;
    if (status === OVERTIME_STATUS.REJECTED) return <XCircle size={20} className="text-error" />;
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
            <h1 className="text-display-md sm:text-display-lg">Pengajuan Lembur</h1>
            <p className="mt-2 font-serif text-body">Ajukan lembur dan pantau persetujuan manager</p>
          </div>
          <Button onClick={handleOpenModal}>
            <Plus size={20} />
            <span>Ajukan Lembur</span>
          </Button>
        </div>

        {/* Info */}
        <div className="p-4 bg-surface-soft border-2 border-ink rounded-xl flex items-start gap-3">
          <Info className="w-5 h-5 text-muted mt-0.5 shrink-0" />
          <div className="font-serif text-sm text-body">
            <p className="caption-uppercase">Cara kerja lembur</p>
            <p className="mt-1">
              Lembur dihitung setelah jam kerja wajib hari ini terpenuhi dan harus disetujui manager.
              Anda bisa mengajukan lembur lebih dulu di sini, atau mengisinya saat check-in setelah jam wajib selesai.
              Anda akan otomatis check-out saat durasi lembur habis.
            </p>
          </div>
        </div>

        {/* Overtime Requests List */}
        <Card>
          {overtimes.length === 0 ? (
            <div className="text-center py-16">
              <Timer size={48} className="mx-auto text-muted mb-4" />
              <p className="caption-uppercase">Belum ada pengajuan lembur</p>
              <Button
                onClick={handleOpenModal}
                variant="outline"
                className="mt-4"
              >
                Ajukan Lembur Pertama Anda
              </Button>
            </div>
          ) : (
            <div className="space-y-4">
              {overtimes.map((overtime) => (
                <div
                  key={overtime.id}
                  className="border-2 border-ink rounded-card p-4 hover:shadow-brutal-sm transition-all"
                >
                  <div className="flex flex-wrap items-start justify-between gap-3 mb-3">
                    <div className="flex items-start gap-3">
                      {getStatusIcon(overtime.status)}
                      <div>
                        <p className="font-mono text-sm text-ink">{formatDate(overtime.date)}</p>
                        <p className="caption-uppercase mt-1">
                          Diajukan pada {formatDateTime(overtime.requested_at)}
                        </p>
                      </div>
                    </div>
                    <div className="flex flex-wrap items-center gap-2">
                      {overtime.source === 'manager' && (
                        <span className="badge badge-info">Ditugaskan manager</span>
                      )}
                      <span className={`badge ${OVERTIME_STATUS_BADGES[overtime.status] || 'badge-info'}`}>
                        {OVERTIME_STATUS_LABELS[overtime.status] || overtime.status}
                      </span>
                    </div>
                  </div>

                  <div className="grid grid-cols-1 gap-3 sm:grid-cols-3 mb-3">
                    <div className="border-2 border-ink rounded-xl bg-surface-soft p-3">
                      <p className="caption-uppercase mb-1">Durasi Diajukan</p>
                      <p className="font-mono text-sm text-ink">{formatMinutes(overtime.planned_minutes)}</p>
                    </div>
                    <div className="border-2 border-ink rounded-xl bg-surface-soft p-3">
                      <p className="caption-uppercase mb-1">Durasi Disetujui</p>
                      <p className="font-mono text-sm text-ink">
                        {overtime.status === OVERTIME_STATUS.APPROVED
                          ? formatMinutes(overtime.allowed_minutes)
                          : '-'}
                      </p>
                    </div>
                    <div className="border-2 border-ink rounded-xl bg-surface-soft p-3">
                      <p className="caption-uppercase mb-1">Jam Terpakai</p>
                      <p className="font-mono text-sm text-ink">{formatHours(overtime.used_hours)}</p>
                    </div>
                  </div>

                  <div className="bg-surface-soft border-2 border-ink rounded-xl p-3 mb-3">
                    <p className="caption-uppercase mb-1">Alasan:</p>
                    <p className="font-serif text-sm text-body">{overtime.reason}</p>
                  </div>

                  {overtime.notes && (
                    <div className="bg-surface-soft border-2 border-ink rounded-xl p-3">
                      <p className="caption-uppercase mb-1">Catatan Manager:</p>
                      <p className="font-serif text-sm text-body">{overtime.notes}</p>
                    </div>
                  )}
                </div>
              ))}
            </div>
          )}
        </Card>
      </div>

      {/* Request Overtime Modal */}
      <Modal
        isOpen={showModal}
        onClose={() => setShowModal(false)}
        title="Ajukan Lembur"
        size="md"
      >
        <form onSubmit={handleSubmit}>
          <div className="flex flex-col gap-4">
            <div className="p-4 bg-surface-soft border-2 border-ink rounded-xl flex items-start gap-3">
              <Info className="w-5 h-5 text-muted mt-0.5 shrink-0" />
              <div className="font-serif text-sm text-body">
                <p className="caption-uppercase">Informasi Pengajuan Lembur</p>
                <p className="mt-1">
                  Pengajuan hanya berlaku untuk <strong className="font-normal text-ink">hari ini</strong> dan
                  hanya satu pengajuan aktif per hari. Durasi yang diajukan menjadi batas lembur: Anda akan
                  otomatis check-out saat durasi habis.
                </p>
              </div>
            </div>

            <OvertimeReasonField
              value={formData.reason}
              onChange={(reason) => setFormData({ ...formData, reason })}
            />

            <OvertimeDurationSelect
              value={formData.planned_minutes}
              onChange={(planned_minutes) => setFormData({ ...formData, planned_minutes })}
            />

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
          </div>
        </form>
      </Modal>
    </MainLayout>
  );
};
