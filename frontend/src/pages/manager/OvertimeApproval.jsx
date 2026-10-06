import { useState, useEffect, useRef, useCallback } from 'react';
import { MainLayout } from '../../components/layout/MainLayout';
import { Card } from '../../components/common/Card';
import { Button } from '../../components/common/Button';
import { Input } from '../../components/common/Input';
import { Loading } from '../../components/common/Loading';
import { Modal } from '../../components/common/Modal';
import { OvertimeReasonField, OvertimeDurationSelect } from '../../components/overtime/OvertimeFields';
import {
  getAllOvertimeRequests,
  approveOvertime,
  rejectOvertime,
  assignOvertime,
  getAllUsers,
} from '../../api/manager.api';
import { refreshPendingOvertimeCount } from '../../hooks/usePendingOvertimeCount';
import { formatDate, formatDateTime, formatHours, formatMinutes } from '../../utils/dateHelpers';
import {
  OVERTIME_STATUS,
  OVERTIME_STATUS_LABELS,
  OVERTIME_STATUS_BADGES,
  OVERTIME_MIN_MINUTES,
  OVERTIME_MAX_MINUTES,
  OVERTIME_DEFAULT_MINUTES,
  OVERTIME_REASON_MIN_LENGTH,
} from '../../utils/constants';
import { usePageTitle } from '../../hooks/usePageTitle';
import { Timer, CheckCircle, XCircle, Clock, UserPlus } from 'lucide-react';
import toast from 'react-hot-toast';

const REJECT_NOTES_MIN_LENGTH = 3;
const NOTES_MAX_LENGTH = 500;
const EMPTY_ASSIGN_FORM = { user_id: '', planned_minutes: OVERTIME_DEFAULT_MINUTES, reason: '' };

const errorFieldClass = (hasError) =>
  `input-field ${hasError ? 'border-error bg-error/5 focus:border-error focus:shadow-none' : ''}`;

const FormError = ({ message }) =>
  message ? (
    <p role="alert" className="mt-2 font-sans text-sm text-error">
      {message}
    </p>
  ) : null;

export const OvertimeApproval = () => {
  usePageTitle('Persetujuan Lembur');
  const [loading, setLoading] = useState(true);
  const [overtimes, setOvertimes] = useState([]);
  const [filterStatus, setFilterStatus] = useState(OVERTIME_STATUS.PENDING);

  // Approve / reject modal
  const [showModal, setShowModal] = useState(false);
  const [selectedOvertime, setSelectedOvertime] = useState(null);
  const [actionType, setActionType] = useState(null);
  const [approvedMinutes, setApprovedMinutes] = useState('');
  const [notes, setNotes] = useState('');
  const [formError, setFormError] = useState('');
  const [submitting, setSubmitting] = useState(false);

  // Assign modal
  const [showAssignModal, setShowAssignModal] = useState(false);
  const [employees, setEmployees] = useState([]);
  const [loadingEmployees, setLoadingEmployees] = useState(false);
  const [assignForm, setAssignForm] = useState(EMPTY_ASSIGN_FORM);
  const [assignError, setAssignError] = useState('');
  const [assignSubmitting, setAssignSubmitting] = useState(false);

  // Ignore responses of superseded requests (the filter can change while a fetch is in flight).
  const requestIdRef = useRef(0);

  const fetchOvertimes = useCallback(async () => {
    const requestId = ++requestIdRef.current;
    try {
      setLoading(true);
      const response = await getAllOvertimeRequests(filterStatus);

      if (requestId === requestIdRef.current && response.success) {
        setOvertimes(response.data);
      }
    } catch (error) {
      console.error('Error fetching overtimes:', error);
      if (requestId === requestIdRef.current) {
        toast.error('Gagal mengambil pengajuan lembur');
      }
    } finally {
      if (requestId === requestIdRef.current) {
        setLoading(false);
      }
    }
  }, [filterStatus]);

  useEffect(() => {
    fetchOvertimes();
  }, [fetchOvertimes]);

  // Keep the sidebar badge in sync with what this page shows.
  useEffect(() => {
    refreshPendingOvertimeCount();
  }, []);

  const handleOpenModal = (overtime, type) => {
    setSelectedOvertime(overtime);
    setActionType(type);
    setApprovedMinutes(String(overtime.planned_minutes));
    setNotes('');
    setFormError('');
    setShowModal(true);
  };

  const handleCloseModal = () => {
    setShowModal(false);
    setSelectedOvertime(null);
    setActionType(null);
    setApprovedMinutes('');
    setNotes('');
    setFormError('');
  };

  const handleSubmit = async (e) => {
    e.preventDefault();

    const trimmedNotes = notes.trim();
    let payload = {};

    if (actionType === 'approve') {
      const minutes = Number(approvedMinutes);
      if (!Number.isInteger(minutes) || minutes < OVERTIME_MIN_MINUTES || minutes > OVERTIME_MAX_MINUTES) {
        setFormError(`Durasi disetujui harus berupa angka antara ${OVERTIME_MIN_MINUTES} dan ${OVERTIME_MAX_MINUTES} menit`);
        return;
      }
      // Only send an override when the manager actually changed the requested duration.
      if (minutes !== selectedOvertime.planned_minutes) {
        payload.approved_minutes = minutes;
      }
      if (trimmedNotes) {
        payload.notes = trimmedNotes;
      }
    } else if (trimmedNotes.length < REJECT_NOTES_MIN_LENGTH) {
      setFormError(
        trimmedNotes.length === 0
          ? 'Catatan penolakan wajib diisi'
          : `Catatan penolakan minimal ${REJECT_NOTES_MIN_LENGTH} karakter`
      );
      return;
    }

    try {
      setSubmitting(true);
      setFormError('');

      const response = actionType === 'approve'
        ? await approveOvertime(selectedOvertime.id, payload)
        : await rejectOvertime(selectedOvertime.id, trimmedNotes);

      if (response.success) {
        toast.success(response.message || (actionType === 'approve' ? 'Pengajuan lembur disetujui' : 'Pengajuan lembur ditolak'));
        handleCloseModal();
        fetchOvertimes();
        refreshPendingOvertimeCount();
      }
    } catch (error) {
      const message = error.response?.data?.message || 'Operasi gagal';
      toast.error(message);
      // The request may have been decided by someone else meanwhile: resync the list and the badge.
      fetchOvertimes();
      refreshPendingOvertimeCount();
    } finally {
      setSubmitting(false);
    }
  };

  const handleOpenAssignModal = async () => {
    setAssignForm(EMPTY_ASSIGN_FORM);
    setAssignError('');
    setShowAssignModal(true);

    if (employees.length > 0) return;
    try {
      setLoadingEmployees(true);
      const response = await getAllUsers(1, 1000);
      if (response.success) {
        setEmployees(response.data.filter((user) => user.role === 'employee' && !user.is_disabled));
      }
    } catch (error) {
      console.error('Error fetching employees:', error);
      toast.error('Gagal mengambil daftar karyawan');
    } finally {
      setLoadingEmployees(false);
    }
  };

  const handleCloseAssignModal = () => {
    setShowAssignModal(false);
    setAssignError('');
  };

  const handleAssignSubmit = async (e) => {
    e.preventDefault();

    const reason = assignForm.reason.trim();
    if (!assignForm.user_id) {
      setAssignError('Karyawan harus dipilih');
      return;
    }
    if (reason.length < OVERTIME_REASON_MIN_LENGTH) {
      setAssignError(`Alasan harus minimal ${OVERTIME_REASON_MIN_LENGTH} karakter`);
      return;
    }

    try {
      setAssignSubmitting(true);
      setAssignError('');
      const response = await assignOvertime({
        userId: Number(assignForm.user_id),
        plannedMinutes: assignForm.planned_minutes,
        reason,
      });

      if (response.success) {
        toast.success(response.message || 'Lembur berhasil ditugaskan');
        handleCloseAssignModal();
        fetchOvertimes();
        refreshPendingOvertimeCount();
      }
    } catch (error) {
      const message = error.response?.data?.message || 'Gagal menugaskan lembur';
      toast.error(message);
    } finally {
      setAssignSubmitting(false);
    }
  };

  const getStatusIcon = (status) => {
    if (status === OVERTIME_STATUS.APPROVED) return <CheckCircle size={20} className="text-success" />;
    if (status === OVERTIME_STATUS.REJECTED) return <XCircle size={20} className="text-error" />;
    return <Clock size={20} className="text-warning" />;
  };

  return (
    <MainLayout>
      <div className="space-y-6 sm:space-y-8">
        {/* Header */}
        <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
          <div>
            <h1 className="text-display-md sm:text-display-lg">Persetujuan Lembur</h1>
            <p className="mt-2 font-serif text-body">Tinjau pengajuan lembur karyawan atau tugaskan lembur</p>
          </div>
          <div className="flex flex-col gap-3 sm:flex-row sm:items-center">
            <select
              value={filterStatus}
              onChange={(e) => setFilterStatus(e.target.value)}
              className="input-field sm:w-48"
              aria-label="Filter status"
            >
              <option value="">Semua Status</option>
              <option value={OVERTIME_STATUS.PENDING}>{OVERTIME_STATUS_LABELS.pending}</option>
              <option value={OVERTIME_STATUS.APPROVED}>{OVERTIME_STATUS_LABELS.approved}</option>
              <option value={OVERTIME_STATUS.REJECTED}>{OVERTIME_STATUS_LABELS.rejected}</option>
            </select>
            <Button onClick={handleOpenAssignModal}>
              <UserPlus size={20} />
              <span>Tugaskan Lembur</span>
            </Button>
          </div>
        </div>

        {/* Overtime Requests List */}
        <Card>
          {loading ? (
            <Loading />
          ) : overtimes.length === 0 ? (
            <div className="text-center py-16">
              <Timer size={48} className="mx-auto text-muted mb-4" />
              <p className="caption-uppercase">
                {filterStatus
                  ? `Tidak ada pengajuan lembur ${OVERTIME_STATUS_LABELS[filterStatus].toLowerCase()} ditemukan`
                  : 'Tidak ada pengajuan lembur ditemukan'}
              </p>
            </div>
          ) : (
            <div className="space-y-4">
              {overtimes.map((overtime) => (
                <article
                  key={overtime.id}
                  className="rounded-card border-2 border-ink bg-white p-4 shadow-brutal-sm transition-all hover:shadow-brutal"
                >
                  <div className="flex flex-wrap items-start justify-between gap-3 mb-3">
                    <div className="flex items-start gap-3">
                      {getStatusIcon(overtime.status)}
                      <div>
                        <p className="font-serif text-ink">{overtime.user?.name}</p>
                        <p className="font-serif text-sm text-muted break-all">{overtime.user?.email}</p>
                        <p className="font-mono text-sm text-muted mt-1">{formatDate(overtime.date)}</p>
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
                    <div className="rounded-xl border-2 border-ink bg-surface-soft p-3">
                      <p className="caption-uppercase mb-1">Durasi Diminta</p>
                      <p className="font-mono text-sm text-ink">{formatMinutes(overtime.planned_minutes)}</p>
                    </div>
                    <div className="rounded-xl border-2 border-ink bg-surface-soft p-3">
                      <p className="caption-uppercase mb-1">Durasi Disetujui</p>
                      <p className="font-mono text-sm text-ink">
                        {overtime.status === OVERTIME_STATUS.APPROVED
                          ? formatMinutes(overtime.allowed_minutes)
                          : '-'}
                      </p>
                    </div>
                    <div className="rounded-xl border-2 border-ink bg-surface-soft p-3">
                      <p className="caption-uppercase mb-1">Jam Terpakai</p>
                      <p className="font-mono text-sm text-ink">{formatHours(overtime.used_hours)}</p>
                    </div>
                  </div>

                  <div className="rounded-xl border-2 border-ink bg-surface-soft p-3 mb-3">
                    <p className="caption-uppercase mb-1">Alasan:</p>
                    <p className="font-serif text-sm text-body">{overtime.reason}</p>
                  </div>

                  {overtime.notes && (
                    <div className="rounded-xl border-2 border-ink bg-surface-soft p-3 mb-3">
                      <p className="caption-uppercase mb-1">Catatan:</p>
                      <p className="font-serif text-sm text-body">{overtime.notes}</p>
                    </div>
                  )}

                  {overtime.approver && overtime.status !== OVERTIME_STATUS.PENDING && (
                    <p className="caption-uppercase mb-3">
                      {overtime.source === 'manager'
                        ? 'Ditugaskan'
                        : overtime.status === OVERTIME_STATUS.APPROVED ? 'Disetujui' : 'Ditolak'}{' '}
                      oleh {overtime.approver.name} pada {formatDateTime(overtime.approved_at)}
                    </p>
                  )}

                  {overtime.status === OVERTIME_STATUS.PENDING && (
                    <div className="flex flex-col gap-3 sm:flex-row sm:items-center">
                      <button
                        onClick={() => handleOpenModal(overtime, 'approve')}
                        className="btn-success w-full sm:w-auto sm:flex-1"
                      >
                        <CheckCircle size={20} />
                        <span>Setujui</span>
                      </button>
                      <button
                        onClick={() => handleOpenModal(overtime, 'reject')}
                        className="btn-danger w-full sm:w-auto sm:flex-1"
                      >
                        <XCircle size={20} />
                        <span>Tolak</span>
                      </button>
                    </div>
                  )}
                </article>
              ))}
            </div>
          )}
        </Card>
      </div>

      {/* Approve/Reject Modal */}
      <Modal
        isOpen={showModal}
        onClose={handleCloseModal}
        title={actionType === 'approve' ? 'Setujui Pengajuan Lembur' : 'Tolak Pengajuan Lembur'}
        size="md"
      >
        {selectedOvertime && (
          <form onSubmit={handleSubmit}>
            <div className="flex flex-col gap-4">
              <div className="flex flex-col gap-2">
                <p className="font-serif text-sm text-body">
                  <strong className="font-normal text-body-strong">Karyawan:</strong> {selectedOvertime.user?.name}
                </p>
                <p className="font-serif text-sm text-body">
                  <strong className="font-normal text-body-strong">Tanggal:</strong> {formatDate(selectedOvertime.date)}
                </p>
                <p className="font-serif text-sm text-body">
                  <strong className="font-normal text-body-strong">Durasi diminta:</strong> {formatMinutes(selectedOvertime.planned_minutes)}
                </p>
                <p className="font-serif text-sm text-body">
                  <strong className="font-normal text-body-strong">Alasan:</strong> {selectedOvertime.reason}
                </p>
              </div>

              {actionType === 'approve' && (
                <Input
                  label="Durasi disetujui (menit)"
                  type="number"
                  min={OVERTIME_MIN_MINUTES}
                  max={OVERTIME_MAX_MINUTES}
                  step={1}
                  value={approvedMinutes}
                  onChange={(e) => {
                    setApprovedMinutes(e.target.value);
                    setFormError('');
                  }}
                  required
                  containerClassName=""
                  error={formError}
                  helperText={`Setara ${formatMinutes(approvedMinutes)}. Karyawan otomatis check-out saat durasi ini habis.`}
                />
              )}

              <div>
                <label htmlFor="overtime-notes" className="caption-uppercase block mb-2">
                  {actionType === 'approve'
                    ? 'Catatan (Opsional)'
                    : <>Catatan Penolakan <span className="text-error">*</span></>}
                </label>
                <textarea
                  id="overtime-notes"
                  value={notes}
                  onChange={(e) => {
                    setNotes(e.target.value);
                    if (actionType === 'reject') setFormError('');
                  }}
                  className={errorFieldClass(actionType === 'reject' && formError)}
                  rows="4"
                  maxLength={NOTES_MAX_LENGTH}
                  placeholder={actionType === 'approve'
                    ? 'Tambahkan catatan persetujuan (opsional)'
                    : 'Berikan alasan penolakan (wajib diisi)'}
                  aria-invalid={actionType === 'reject' && formError ? true : undefined}
                />
                {actionType === 'reject' && <FormError message={formError} />}
              </div>

              <div className="flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
                <Button
                  type="button"
                  variant="secondary"
                  onClick={handleCloseModal}
                >
                  Batal
                </Button>
                <Button
                  type="submit"
                  disabled={submitting}
                  variant={actionType === 'approve' ? 'success' : 'danger'}
                >
                  {submitting ? 'Memproses...' : actionType === 'approve' ? 'Setujui' : 'Tolak'}
                </Button>
              </div>
            </div>
          </form>
        )}
      </Modal>

      {/* Assign Overtime Modal */}
      <Modal
        isOpen={showAssignModal}
        onClose={handleCloseAssignModal}
        title="Tugaskan Lembur"
        size="md"
      >
        <form onSubmit={handleAssignSubmit}>
          <div className="flex flex-col gap-4">
            <p className="font-serif text-sm text-body">
              Lembur ditugaskan untuk <strong className="font-normal text-ink">hari ini</strong> dan langsung
              berstatus disetujui. Jika karyawan masih punya pengajuan yang menunggu, setujui atau tolak dulu.
            </p>

            <div>
              <label htmlFor="assign-employee" className="caption-uppercase block mb-2">
                Karyawan <span className="text-error">*</span>
              </label>
              <select
                id="assign-employee"
                value={assignForm.user_id}
                onChange={(e) => {
                  setAssignForm({ ...assignForm, user_id: e.target.value });
                  setAssignError('');
                }}
                className="input-field"
                disabled={loadingEmployees}
              >
                <option value="">{loadingEmployees ? 'Memuat karyawan...' : 'Pilih karyawan'}</option>
                {employees.map((employee) => (
                  <option key={employee.id} value={employee.id}>
                    {employee.name} ({employee.email})
                  </option>
                ))}
              </select>
            </div>

            <OvertimeDurationSelect
              value={assignForm.planned_minutes}
              onChange={(planned_minutes) => setAssignForm({ ...assignForm, planned_minutes })}
            />

            <div>
              <OvertimeReasonField
                value={assignForm.reason}
                onChange={(reason) => {
                  setAssignForm({ ...assignForm, reason });
                  setAssignError('');
                }}
                placeholder={`Alasan penugasan lembur (minimal ${OVERTIME_REASON_MIN_LENGTH} karakter)`}
              />
              <FormError message={assignError} />
            </div>

            <div className="flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
              <Button
                type="button"
                variant="secondary"
                onClick={handleCloseAssignModal}
              >
                Batal
              </Button>
              <Button type="submit" disabled={assignSubmitting}>
                {assignSubmitting ? 'Menugaskan...' : 'Tugaskan'}
              </Button>
            </div>
          </div>
        </form>
      </Modal>
    </MainLayout>
  );
};
