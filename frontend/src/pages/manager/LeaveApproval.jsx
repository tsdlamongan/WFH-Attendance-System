import { useState, useEffect } from 'react';
import { MainLayout } from '../../components/layout/MainLayout';
import { Card } from '../../components/common/Card';
import { Button } from '../../components/common/Button';
import { Loading } from '../../components/common/Loading';
import { Modal } from '../../components/common/Modal';
import { getAllLeaveRequests, approveLeave, rejectLeave, editLeave } from '../../api/manager.api';
import { formatDate, formatDateTime, formatDateForInput, formatDateTimeForInput } from '../../utils/dateHelpers';
import { usePageTitle } from '../../hooks/usePageTitle';
import { ClipboardList, CheckCircle, XCircle, Clock, Edit3 } from 'lucide-react';
import toast from 'react-hot-toast';

export const LeaveApproval = () => {
  usePageTitle('Persetujuan Cuti');
  const [loading, setLoading] = useState(true);
  const [leaves, setLeaves] = useState([]);
  const [filterStatus, setFilterStatus] = useState('pending');
  const [showModal, setShowModal] = useState(false);
  const [selectedLeave, setSelectedLeave] = useState(null);
  const [actionType, setActionType] = useState(null);
  const [notes, setNotes] = useState('');
  const [submitting, setSubmitting] = useState(false);

  const [showEditModal, setShowEditModal] = useState(false);
  const [editingLeave, setEditingLeave] = useState(null);
  const [editForm, setEditForm] = useState({
    start_date: '',
    end_date: '',
    created_at: '',
    approved_at: '',
  });
  const [editSubmitting, setEditSubmitting] = useState(false);

  useEffect(() => {
    fetchLeaves();
  }, [filterStatus]);

  const fetchLeaves = async () => {
    try {
      setLoading(true);
      const response = await getAllLeaveRequests(filterStatus);
      
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

  const handleOpenModal = (leave, type) => {
    setSelectedLeave(leave);
    setActionType(type);
    setNotes('');
    setShowModal(true);
  };

  const handleCloseModal = () => {
    setShowModal(false);
    setSelectedLeave(null);
    setActionType(null);
    setNotes('');
  };

  const handleSubmit = async (e) => {
    e.preventDefault();

    try {
      setSubmitting(true);
      
      let response;
      if (actionType === 'approve') {
        response = await approveLeave(selectedLeave.id, notes);
      } else {
        response = await rejectLeave(selectedLeave.id, notes);
      }
      
      if (response.success) {
        toast.success(response.message);
        handleCloseModal();
        fetchLeaves();
      }
    } catch (error) {
      const message = error.response?.data?.message || 'Operasi gagal';
      toast.error(message);
    } finally {
      setSubmitting(false);
    }
  };

  const handleOpenEditModal = (leave) => {
    setEditingLeave(leave);
    setEditForm({
      start_date: formatDateForInput(leave.start_date),
      end_date: formatDateForInput(leave.end_date),
      created_at: formatDateTimeForInput(leave.requested_at),
      approved_at: leave.approved_at ? formatDateTimeForInput(leave.approved_at) : '',
    });
    setShowEditModal(true);
  };

  const handleCloseEditModal = () => {
    setShowEditModal(false);
    setEditingLeave(null);
  };

  const handleEditSubmit = async (e) => {
    e.preventDefault();

    const data = {};
    if (editForm.start_date) data.start_date = editForm.start_date;
    if (editForm.end_date) data.end_date = editForm.end_date;
    if (editForm.created_at) data.created_at = editForm.created_at;
    if (editForm.approved_at) {
      data.approved_at = editForm.approved_at;
    } else if (editingLeave.approved_at && editForm.approved_at === '') {
      data.approved_at = null;
    }

    try {
      setEditSubmitting(true);
      const response = await editLeave(editingLeave.id, data);
      if (response.success) {
        toast.success(response.message);
        handleCloseEditModal();
        fetchLeaves();
      }
    } catch (error) {
      const message = error.response?.data?.message || 'Gagal memperbarui pengajuan cuti';
      toast.error(message);
    } finally {
      setEditSubmitting(false);
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
    if (status === 'approved') return <CheckCircle size={20} className="text-green-600" />;
    if (status === 'rejected') return <XCircle size={20} className="text-red-600" />;
    return <Clock size={20} className="text-yellow-600" />;
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
        <div className="flex items-center justify-between">
          <div>
            <h1 className="text-3xl font-bold text-gray-900">Persetujuan Cuti</h1>
            <p className="text-gray-600 mt-1">Tinjau dan kelola pengajuan cuti karyawan</p>
          </div>
          <div>
            <select
              value={filterStatus}
              onChange={(e) => setFilterStatus(e.target.value)}
              className="input-field"
            >
              <option value="">Semua Status</option>
              <option value="pending">Menunggu</option>
              <option value="approved">Disetujui</option>
              <option value="rejected">Ditolak</option>
            </select>
          </div>
        </div>

        {/* Leave Requests List */}
        <Card>
          {leaves.length === 0 ? (
            <div className="text-center py-12">
              <ClipboardList size={48} className="mx-auto text-gray-400 mb-4" />
              <p className="text-gray-600">
                {filterStatus 
                  ? `Tidak ada pengajuan cuti ${filterStatus === 'pending' ? 'menunggu' : filterStatus === 'approved' ? 'disetujui' : 'ditolak'} ditemukan`
                  : 'Tidak ada pengajuan cuti ditemukan'}
              </p>
            </div>
          ) : (
            <div className="space-y-4">
              {leaves.map((leave) => (
                <div
                  key={leave.id}
                  className="border border-gray-200 rounded-lg p-4 hover:shadow-md transition-shadow"
                >
                  <div className="flex items-start justify-between mb-3">
                    <div className="flex items-start space-x-3">
                      {getStatusIcon(leave.status)}
                      <div>
                        <p className="font-semibold text-gray-900">{leave.user.name}</p>
                        <p className="text-sm text-gray-600">{leave.user.email}</p>
                        <p className="text-sm text-gray-600 mt-1">
                          {formatDate(leave.start_date)} - {formatDate(leave.end_date)}
                        </p>
                      </div>
                    </div>
                    <div className="flex items-center gap-2">
                      <button
                        onClick={() => handleOpenEditModal(leave)}
                        className="inline-flex items-center space-x-1 text-sm text-gray-500 hover:text-primary-600 font-medium transition-colors"
                        title="Edit tanggal"
                      >
                        <Edit3 size={16} />
                      </button>
                      <span className={`badge ${getStatusBadge(leave.status)}`}>
                        {leave.status === 'pending' ? 'Menunggu' : leave.status === 'approved' ? 'Disetujui' : 'Ditolak'}
                      </span>
                    </div>
                  </div>

                  <div className="bg-gray-50 rounded p-3 mb-3">
                    <p className="text-sm font-medium text-gray-700 mb-1">Alasan:</p>
                    <p className="text-sm text-gray-600">{leave.reason}</p>
                  </div>

                  {leave.notes && (
                    <div className="bg-blue-50 rounded p-3 mb-3">
                      <p className="text-sm font-medium text-blue-700 mb-1">Catatan:</p>
                      <p className="text-sm text-blue-600">{leave.notes}</p>
                    </div>
                  )}

                  {leave.approver && (
                    <p className="text-xs text-gray-500 mb-3">
                      {leave.status === 'approved' ? 'Disetujui' : 'Ditolak'} oleh {leave.approver.name} pada {formatDate(leave.approved_at)}
                    </p>
                  )}

                  {leave.status === 'pending' && (
                    <div className="flex items-center gap-3">
                      <button
                        onClick={() => handleOpenModal(leave, 'approve')}
                        className="flex-1 group relative flex items-center justify-center space-x-2 px-4 py-3 bg-gradient-to-r from-green-500 to-emerald-600 hover:from-green-600 hover:to-emerald-700 text-white font-semibold rounded-lg shadow-md hover:shadow-xl transition-all duration-200 transform hover:scale-[1.02] active:scale-95 overflow-hidden"
                      >
                        <div className="absolute inset-0 bg-white opacity-0 group-hover:opacity-20 transition-opacity duration-200"></div>
                        <CheckCircle size={20} className="relative z-10" />
                        <span className="relative z-10">Setujui</span>
                      </button>
                      <button
                        onClick={() => handleOpenModal(leave, 'reject')}
                        className="flex-1 group relative flex items-center justify-center space-x-2 px-4 py-3 bg-gradient-to-r from-red-500 to-rose-600 hover:from-red-600 hover:to-rose-700 text-white font-semibold rounded-lg shadow-md hover:shadow-xl transition-all duration-200 transform hover:scale-[1.02] active:scale-95 overflow-hidden"
                      >
                        <div className="absolute inset-0 bg-white opacity-0 group-hover:opacity-20 transition-opacity duration-200"></div>
                        <XCircle size={20} className="relative z-10" />
                        <span className="relative z-10">Tolak</span>
                      </button>
                    </div>
                  )}
                </div>
              ))}
            </div>
          )}
        </Card>
      </div>

      {/* Approve/Reject Modal */}
      <Modal
        isOpen={showModal}
        onClose={handleCloseModal}
        title={actionType === 'approve' ? 'Setujui Pengajuan Cuti' : 'Tolak Pengajuan Cuti'}
        size="md"
      >
        {selectedLeave && (
          <form onSubmit={handleSubmit}>
            <div className="mb-4">
              <p className="text-sm text-gray-600 mb-2">
                <strong>Karyawan:</strong> {selectedLeave.user.name}
              </p>
              <p className="text-sm text-gray-600 mb-2">
                <strong>Periode:</strong> {formatDate(selectedLeave.start_date)} - {formatDate(selectedLeave.end_date)}
              </p>
              <p className="text-sm text-gray-600">
                <strong>Alasan:</strong> {selectedLeave.reason}
              </p>
            </div>

            <div className="mb-4">
              <label className="block text-sm font-medium text-gray-700 mb-2">
                Catatan (Opsional)
              </label>
              <textarea
                value={notes}
                onChange={(e) => setNotes(e.target.value)}
                className="input-field"
                rows="4"
                placeholder={actionType === 'approve' 
                  ? 'Tambahkan catatan persetujuan (opsional)' 
                  : 'Berikan alasan penolakan (opsional)'}
              />
            </div>

            <div className="flex justify-end space-x-3">
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
          </form>
        )}
      </Modal>

      {/* Edit Leave Modal */}
      <Modal
        isOpen={showEditModal}
        onClose={handleCloseEditModal}
        title="Edit Pengajuan Cuti"
        size="md"
      >
        {editingLeave && (
          <form onSubmit={handleEditSubmit}>
            <div className="mb-4 p-3 bg-gray-50 rounded-lg">
              <p className="text-sm text-gray-600">
                <strong>Karyawan:</strong> {editingLeave.user.name}
              </p>
              <p className="text-sm text-gray-600">
                <strong>Status:</strong>{' '}
                {editingLeave.status === 'pending' ? 'Menunggu' : editingLeave.status === 'approved' ? 'Disetujui' : 'Ditolak'}
              </p>
            </div>

            <div className="space-y-4">
              <div>
                <label className="block text-sm font-medium text-gray-700 mb-1">
                  Tanggal Mulai Cuti
                </label>
                <input
                  type="date"
                  value={editForm.start_date}
                  onChange={(e) => setEditForm({ ...editForm, start_date: e.target.value })}
                  className="input-field"
                  required
                />
              </div>

              <div>
                <label className="block text-sm font-medium text-gray-700 mb-1">
                  Tanggal Selesai Cuti
                </label>
                <input
                  type="date"
                  value={editForm.end_date}
                  onChange={(e) => setEditForm({ ...editForm, end_date: e.target.value })}
                  className="input-field"
                  required
                  min={editForm.start_date}
                />
              </div>

              <div>
                <label className="block text-sm font-medium text-gray-700 mb-1">
                  Tanggal Pengajuan
                </label>
                <input
                  type="datetime-local"
                  value={editForm.created_at}
                  onChange={(e) => setEditForm({ ...editForm, created_at: e.target.value })}
                  className="input-field"
                  required
                />
              </div>

              <div>
                <label className="block text-sm font-medium text-gray-700 mb-1">
                  Tanggal Disetujui/Ditolak
                </label>
                <input
                  type="datetime-local"
                  value={editForm.approved_at}
                  onChange={(e) => setEditForm({ ...editForm, approved_at: e.target.value })}
                  className="input-field"
                />
                <p className="text-xs text-gray-500 mt-1">Kosongkan jika belum ada keputusan</p>
              </div>
            </div>

            <div className="flex justify-end space-x-3 mt-6">
              <Button
                type="button"
                variant="secondary"
                onClick={handleCloseEditModal}
              >
                Batal
              </Button>
              <Button type="submit" disabled={editSubmitting}>
                {editSubmitting ? 'Menyimpan...' : 'Simpan Perubahan'}
              </Button>
            </div>
          </form>
        )}
      </Modal>
    </MainLayout>
  );
};
