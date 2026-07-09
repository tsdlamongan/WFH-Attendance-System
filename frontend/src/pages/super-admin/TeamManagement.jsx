import { useState, useEffect } from 'react';
import { MainLayout } from '../../components/layout/MainLayout';
import { Card } from '../../components/common/Card';
import { Button } from '../../components/common/Button';
import { Loading } from '../../components/common/Loading';
import { getAllTeams, createTeam, updateTeam, deleteTeam } from '../../api/team.api';
import { usePageTitle } from '../../hooks/usePageTitle';
import { Building2, Plus, Edit2, Trash2, Users, CheckCircle, XCircle } from 'lucide-react';
import toast from 'react-hot-toast';

export const TeamManagement = () => {
  usePageTitle('Manajemen Tim');
  const [loading, setLoading] = useState(true);
  const [teams, setTeams] = useState([]);
  const [pagination, setPagination] = useState(null);
  const [showModal, setShowModal] = useState(false);
  const [editingTeam, setEditingTeam] = useState(null);
  const [formData, setFormData] = useState({
    name: '',
    description: '',
    required_work_hours: '7',
    default_leave_quota_days: '12',
    max_leave_days_per_month: '5',
  });

  useEffect(() => {
    fetchTeams();
  }, []);

  const fetchTeams = async () => {
    try {
      setLoading(true);
      const response = await getAllTeams(1000);

      if (response.success) {
        setTeams(response.data);
        setPagination(response.pagination);
      }
    } catch (error) {
      console.error('Error fetching teams:', error);
      toast.error('Gagal mengambil data tim');
    } finally {
      setLoading(false);
    }
  };

  const handleOpenModal = (team = null) => {
    if (team) {
      setEditingTeam(team);
      setFormData({
        name: team.name,
        description: team.description || '',
        required_work_hours: team.required_work_hours,
        default_leave_quota_days: team.default_leave_quota_days,
        max_leave_days_per_month: team.max_leave_days_per_month,
      });
    } else {
      setEditingTeam(null);
      setFormData({
        name: '',
        description: '',
        required_work_hours: '7',
        default_leave_quota_days: '12',
        max_leave_days_per_month: '5',
      });
    }
    setShowModal(true);
  };

  const handleCloseModal = () => {
    setShowModal(false);
    setEditingTeam(null);
  };

  const handleSubmit = async (e) => {
    e.preventDefault();

    try {
      if (editingTeam) {
        const response = await updateTeam(editingTeam.id, formData);
        if (response.success) {
          toast.success('Tim berhasil diperbarui');
        }
      } else {
        const response = await createTeam(formData);
        if (response.success) {
          toast.success('Tim berhasil ditambahkan');
        }
      }

      handleCloseModal();
      fetchTeams();
    } catch (error) {
      const message = error.response?.data?.message || 'Terjadi kesalahan';
      toast.error(message);
    }
  };

  const handleDelete = async (team) => {
    if (!confirm(`Apakah Anda yakin ingin menghapus tim "${team.name}"?`)) {
      return;
    }

    try {
      const response = await deleteTeam(team.id);
      if (response.success) {
        toast.success('Tim berhasil dihapus');
        fetchTeams();
      }
    } catch (error) {
      const message = error.response?.data?.message || 'Gagal menghapus tim';
      toast.error(message);
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
        <div className="flex flex-col gap-4 sm:flex-row sm:justify-between sm:items-center">
          <div>
            <h1 className="text-display-md sm:text-display-lg">Manajemen Tim</h1>
            <p className="mt-2 font-sans text-body">Kelola semua tim dalam sistem</p>
          </div>
          <Button onClick={() => handleOpenModal()} className="flex items-center space-x-2">
            <Plus size={20} />
            <span>Tambah Tim</span>
          </Button>
        </div>

        {/* Teams Grid */}
        <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4 sm:gap-6">
          {teams.map((team) => (
            <Card key={team.id}>
              <div>
                <div className="flex justify-between items-start mb-4">
                  <div className="flex items-center space-x-3">
                    <Building2 className="text-muted" size={24} />
                    <div>
                      <h3 className="text-title-md">{team.name}</h3>
                      <p className="font-mono text-caption text-muted">{team.slug}</p>
                    </div>
                  </div>
                  <div className="flex items-center space-x-1">
                    {team.is_active ? (
                      <CheckCircle size={20} className="text-success" title="Aktif" />
                    ) : (
                      <XCircle size={20} className="text-error" title="Tidak Aktif" />
                    )}
                  </div>
                </div>

                {team.description && (
                  <p className="font-sans text-sm text-body mb-4">{team.description}</p>
                )}

                <div className="space-y-2 mb-6">
                  <div className="flex justify-between items-center">
                    <span className="caption-uppercase">Total Pengguna:</span>
                    <span className="font-mono text-sm text-ink flex items-center">
                      <Users size={16} className="mr-1 text-muted" />
                      {team.users_count || 0}
                    </span>
                  </div>
                  <div className="flex justify-between items-center">
                    <span className="caption-uppercase">Jam Kerja:</span>
                    <span className="font-mono text-sm text-ink">{team.required_work_hours} jam/hari</span>
                  </div>
                  <div className="flex justify-between items-center">
                    <span className="caption-uppercase">Kuota Cuti:</span>
                    <span className="font-mono text-sm text-ink">{team.default_leave_quota_days} hari/tahun</span>
                  </div>
                </div>

                <div className="flex space-x-2">
                  <Button
                    variant="secondary"
                    size="sm"
                    onClick={() => handleOpenModal(team)}
                    className="btn-compact flex-1 flex items-center justify-center space-x-1"
                  >
                    <Edit2 size={16} />
                    <span>Edit</span>
                  </Button>
                  <Button
                    variant="danger"
                    size="sm"
                    onClick={() => handleDelete(team)}
                    className="btn-compact flex-1 flex items-center justify-center space-x-1"
                  >
                    <Trash2 size={16} />
                    <span>Hapus</span>
                  </Button>
                </div>
              </div>
            </Card>
          ))}
        </div>

        {teams.length === 0 && (
          <Card>
            <div className="py-16 text-center">
              <Building2 size={48} className="mx-auto text-muted mb-4" />
              <h3 className="text-title-md mb-2">Belum Ada Tim</h3>
              <p className="font-sans text-body mb-6">Mulai dengan menambahkan tim pertama Anda</p>
              <Button onClick={() => handleOpenModal()}>
                <Plus size={20} className="mr-2" />
                Tambah Tim
              </Button>
            </div>
          </Card>
        )}
      </div>

      {/* Modal */}
      {showModal && (
        <div className="fixed inset-0 bg-ink/50 z-50 flex items-center justify-center p-4">
          <div className="bg-surface-card border-2 border-ink rounded-card shadow-brutal-lg max-w-2xl w-full max-h-[90vh] overflow-y-auto">
            <div className="p-4 sm:p-6">
              <h2 className="text-display-sm mb-6">
                {editingTeam ? 'Edit Tim' : 'Tambah Tim Baru'}
              </h2>

              <form onSubmit={handleSubmit} className="space-y-6">
                <div>
                  <label className="caption-uppercase block mb-2">
                    Nama Tim *
                  </label>
                  <input
                    type="text"
                    value={formData.name}
                    onChange={(e) => setFormData({ ...formData, name: e.target.value })}
                    className="input-field"
                    required
                    placeholder="Contoh: Tim Development"
                  />
                </div>

                <div>
                  <label className="caption-uppercase block mb-2">
                    Deskripsi
                  </label>
                  <textarea
                    value={formData.description}
                    onChange={(e) => setFormData({ ...formData, description: e.target.value })}
                    className="input-field"
                    rows="3"
                    placeholder="Deskripsi singkat tentang tim..."
                  />
                </div>

                <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
                  <div>
                    <label className="caption-uppercase block mb-2">
                      Jam Kerja (per hari) *
                    </label>
                    <input
                      type="number"
                      step="0.5"
                      min="1"
                      max="24"
                      value={formData.required_work_hours}
                      onChange={(e) => setFormData({ ...formData, required_work_hours: e.target.value })}
                      className="input-field"
                      required
                    />
                  </div>

                  <div>
                    <label className="caption-uppercase block mb-2">
                      Kuota Cuti (per tahun) *
                    </label>
                    <input
                      type="number"
                      min="0"
                      max="365"
                      value={formData.default_leave_quota_days}
                      onChange={(e) => setFormData({ ...formData, default_leave_quota_days: e.target.value })}
                      className="input-field"
                      required
                    />
                  </div>

                  <div>
                    <label className="caption-uppercase block mb-2">
                      Max Cuti (per bulan) *
                    </label>
                    <input
                      type="number"
                      min="0"
                      max="31"
                      value={formData.max_leave_days_per_month}
                      onChange={(e) => setFormData({ ...formData, max_leave_days_per_month: e.target.value })}
                      className="input-field"
                      required
                    />
                  </div>
                </div>

                <div className="flex flex-col-reverse gap-3 sm:flex-row sm:justify-end sm:gap-4 pt-4">
                  <Button type="button" variant="secondary" onClick={handleCloseModal}>
                    Batal
                  </Button>
                  <Button type="submit">
                    {editingTeam ? 'Simpan Perubahan' : 'Tambah Tim'}
                  </Button>
                </div>
              </form>
            </div>
          </div>
        </div>
      )}
    </MainLayout>
  );
};
