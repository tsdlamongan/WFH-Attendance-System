import { useState, useEffect, useCallback } from 'react';
import { MainLayout } from '../../components/layout/MainLayout';
import { Card } from '../../components/common/Card';
import { Button } from '../../components/common/Button';
import { Input } from '../../components/common/Input';
import { Loading } from '../../components/common/Loading';
import { Modal } from '../../components/common/Modal';
import { getProjects, createProject, updateProject, deleteProject } from '../../api/manager.api';
import { PROJECT_STATUS, PROJECT_STATUS_LABELS, PROJECT_PROGRESS_OPTIONS } from '../../utils/constants';
import { usePageTitle } from '../../hooks/usePageTitle';
import { FolderKanban, Plus, Edit, Trash2, Ban, CheckCircle } from 'lucide-react';
import toast from 'react-hot-toast';

const EMPTY_FORM = {
  name: '',
  pic: '',
  progress: 0,
  status: PROJECT_STATUS.ON_PROGRESS,
  note: '',
  is_active: true,
};

// Compact select for the table rows (the base .input-field is sized for forms).
const ROW_SELECT_CLASS = 'input-field min-h-[38px] px-2 py-1.5 text-sm';

export const ProjectManagement = () => {
  usePageTitle('Project');
  const [loading, setLoading] = useState(true);
  const [projects, setProjects] = useState([]);
  const [showModal, setShowModal] = useState(false);
  const [editingProject, setEditingProject] = useState(null);
  const [formData, setFormData] = useState(EMPTY_FORM);
  const [submitting, setSubmitting] = useState(false);
  // Row whose inline change (progress / status / active) is being saved.
  const [savingId, setSavingId] = useState(null);

  const fetchProjects = useCallback(async () => {
    try {
      const response = await getProjects();

      if (response.success) {
        setProjects(response.data);
      }
    } catch (error) {
      console.error('Error fetching projects:', error);
      toast.error(error.response?.data?.message || 'Gagal mengambil daftar project');
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    fetchProjects();
  }, [fetchProjects]);

  const handleOpenModal = (project = null) => {
    setEditingProject(project);
    setFormData(project
      ? {
          name: project.name,
          pic: project.pic || '',
          progress: project.progress,
          status: project.status,
          note: project.note || '',
          is_active: project.is_active,
        }
      : EMPTY_FORM);
    setShowModal(true);
  };

  const handleCloseModal = () => {
    setShowModal(false);
    setEditingProject(null);
    setFormData(EMPTY_FORM);
  };

  const handleStatusChange = (status) => {
    // A finished project is 100% by definition; the manager can still lower it afterwards.
    setFormData((prev) => ({
      ...prev,
      status,
      progress: status === PROJECT_STATUS.COMPLETED ? 100 : prev.progress,
    }));
  };

  const handleSubmit = async (e) => {
    e.preventDefault();

    const payload = {
      ...formData,
      name: formData.name.trim(),
      pic: formData.pic.trim(),
      note: formData.note.trim(),
    };

    try {
      setSubmitting(true);

      const response = editingProject
        ? await updateProject(editingProject.id, payload)
        : await createProject(payload);

      if (response.success) {
        toast.success(response.message);
        handleCloseModal();
        fetchProjects();
      }
    } catch (error) {
      const errors = error.response?.data?.errors;
      const message = errors
        ? Object.values(errors).flat().join(' ')
        : error.response?.data?.message || 'Operasi gagal';
      toast.error(message);
    } finally {
      setSubmitting(false);
    }
  };

  // Saves a change made straight in the table and swaps in the server's version of the row.
  const handleQuickUpdate = async (project, changes) => {
    try {
      setSavingId(project.id);
      const response = await updateProject(project.id, { name: project.name, ...changes });

      if (response.success) {
        setProjects((prev) => prev.map((item) => (item.id === project.id ? { ...item, ...response.data } : item)));
        toast.success(response.message);
      }
    } catch (error) {
      toast.error(error.response?.data?.message || 'Gagal memperbarui project');
      fetchProjects();
    } finally {
      setSavingId(null);
    }
  };

  const handleDelete = async (project) => {
    if (!confirm(`Apakah Anda yakin ingin menghapus project ${project.name}?`)) {
      return;
    }

    try {
      const response = await deleteProject(project.id);

      if (response.success) {
        toast.success(response.message);
        fetchProjects();
      }
    } catch (error) {
      toast.error(error.response?.data?.message || 'Gagal menghapus project', { duration: 6000 });
    }
  };

  if (loading) {
    return (
      <MainLayout>
        <Loading />
      </MainLayout>
    );
  }

  const activeProjects = projects.filter((project) => project.is_active);
  const countByStatus = (status) => activeProjects.filter((project) => project.status === status).length;
  const summary = [
    { label: 'Project Aktif', value: activeProjects.length },
    { label: 'Selesai', value: countByStatus(PROJECT_STATUS.COMPLETED) },
    { label: 'On-Progress', value: countByStatus(PROJECT_STATUS.ON_PROGRESS) },
    { label: 'Hold', value: countByStatus(PROJECT_STATUS.HOLD) },
  ];

  return (
    <MainLayout>
      <div className="space-y-6 sm:space-y-8">
        {/* Header */}
        <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
          <div>
            <h1 className="text-display-md sm:text-display-lg">Manajemen Project</h1>
            <p className="mt-2 font-serif text-body">
              Daftar project yang dipilih karyawan saat check-in dan tampil di laporan evaluasi
            </p>
          </div>
          <Button onClick={() => handleOpenModal()} className="self-start sm:self-auto shrink-0">
            <Plus size={20} />
            <span>Tambah Project</span>
          </Button>
        </div>

        {/* Summary */}
        <div className="grid grid-cols-2 gap-4 sm:gap-6 lg:grid-cols-4">
          {summary.map((item) => (
            <Card key={item.label}>
              <p className="caption-uppercase mb-1">{item.label}</p>
              <p className="font-display text-display-md text-ink">{item.value}</p>
            </Card>
          ))}
        </div>

        {/* Projects List */}
        <Card>
          {projects.length === 0 ? (
            <div className="text-center py-16">
              <FolderKanban size={48} className="mx-auto text-muted mb-4" />
              <p className="caption-uppercase">Belum ada project</p>
              <Button onClick={() => handleOpenModal()} variant="outline" className="mt-6">
                Tambah Project Pertama
              </Button>
            </div>
          ) : (
            <div className="rounded-xl border-2 border-ink overflow-hidden">
              <div className="overflow-x-auto">
                <table className="w-full">
                  <thead>
                    <tr className="bg-surface-soft border-b-2 border-ink">
                      <th className="caption-uppercase text-left px-4 py-3">No</th>
                      <th className="caption-uppercase text-left px-4 py-3">Nama Project</th>
                      <th className="caption-uppercase text-left px-4 py-3">PIC</th>
                      <th className="caption-uppercase text-left px-4 py-3">Progress</th>
                      <th className="caption-uppercase text-left px-4 py-3">Status</th>
                      <th className="caption-uppercase text-left px-4 py-3">Catatan</th>
                      <th className="caption-uppercase text-right px-4 py-3">Aksi</th>
                    </tr>
                  </thead>
                  <tbody>
                    {projects.map((project, index) => {
                      const saving = savingId === project.id;
                      const inUse = project.tasks_count > 0;

                      return (
                        <tr
                          key={project.id}
                          data-testid={`project-row-${project.id}`}
                          className={`border-b border-hairline last:border-0 hover:bg-surface-soft transition-colors ${project.is_active ? '' : 'opacity-60'}`}
                        >
                          <td className="px-4 py-3 font-mono text-sm text-muted">{index + 1}</td>
                          <td className="px-4 py-3 min-w-48">
                            <div className="flex flex-wrap items-center gap-2">
                              <p className="font-serif text-body-strong">{project.name}</p>
                              {!project.is_active && <span className="badge badge-muted">Nonaktif</span>}
                            </div>
                            <p className="font-serif text-sm text-muted">{project.tasks_count} tugas tercatat</p>
                          </td>
                          <td className="px-4 py-3 min-w-36 font-serif text-sm">
                            {project.pic || <span className="text-muted" title="Diisi otomatis dari karyawan yang mengerjakan">Otomatis</span>}
                          </td>
                          <td className="px-4 py-3">
                            <select
                              value={project.progress}
                              onChange={(e) => handleQuickUpdate(project, { progress: Number(e.target.value) })}
                              disabled={saving}
                              className={`${ROW_SELECT_CLASS} w-24`}
                              aria-label={`Progress ${project.name}`}
                            >
                              {PROJECT_PROGRESS_OPTIONS.map((progress) => (
                                <option key={progress} value={progress}>{progress}%</option>
                              ))}
                            </select>
                          </td>
                          <td className="px-4 py-3">
                            <select
                              value={project.status}
                              onChange={(e) => handleQuickUpdate(
                                project,
                                e.target.value === PROJECT_STATUS.COMPLETED
                                  ? { status: e.target.value, progress: 100 }
                                  : { status: e.target.value }
                              )}
                              disabled={saving}
                              className={`${ROW_SELECT_CLASS} w-36`}
                              aria-label={`Status ${project.name}`}
                            >
                              {Object.entries(PROJECT_STATUS_LABELS).map(([value, label]) => (
                                <option key={value} value={value}>{label}</option>
                              ))}
                            </select>
                          </td>
                          <td className="px-4 py-3 min-w-36 font-serif text-sm text-body">{project.note || '-'}</td>
                          <td className="px-4 py-3 whitespace-nowrap text-right">
                            <button
                              onClick={() => handleOpenModal(project)}
                              className="p-2 text-muted hover:text-ink transition-colors mr-2"
                              title="Edit"
                              aria-label={`Edit ${project.name}`}
                            >
                              <Edit size={18} />
                            </button>
                            <button
                              onClick={() => handleQuickUpdate(project, { is_active: !project.is_active })}
                              disabled={saving}
                              className={`p-2 transition-colors mr-2 ${project.is_active ? 'text-warning hover:text-ink' : 'text-success hover:text-ink'}`}
                              title={project.is_active ? 'Nonaktifkan (sembunyikan dari check-in)' : 'Aktifkan kembali'}
                              aria-label={`${project.is_active ? 'Nonaktifkan' : 'Aktifkan'} ${project.name}`}
                            >
                              {project.is_active ? <Ban size={18} /> : <CheckCircle size={18} />}
                            </button>
                            <button
                              onClick={() => handleDelete(project)}
                              disabled={inUse}
                              className="p-2 text-muted hover:text-error transition-colors disabled:opacity-40 disabled:cursor-not-allowed disabled:hover:text-muted"
                              title={inUse ? 'Sudah dipakai di data check-in: nonaktifkan saja' : 'Hapus'}
                              aria-label={`Hapus ${project.name}`}
                            >
                              <Trash2 size={18} />
                            </button>
                          </td>
                        </tr>
                      );
                    })}
                  </tbody>
                </table>
              </div>
            </div>
          )}
        </Card>
      </div>

      {/* Add/Edit Project Modal */}
      <Modal
        isOpen={showModal}
        onClose={handleCloseModal}
        title={editingProject ? 'Edit Project' : 'Tambah Project Baru'}
        size="md"
      >
        <form onSubmit={handleSubmit}>
          <Input
            label="Nama Project"
            value={formData.name}
            onChange={(e) => setFormData({ ...formData, name: e.target.value })}
            placeholder="contoh: Santri Link"
            maxLength={150}
            required
          />

          <Input
            label="PIC"
            value={formData.pic}
            onChange={(e) => setFormData({ ...formData, pic: e.target.value })}
            placeholder="contoh: Nico, Fahmi"
            maxLength={255}
            helperText="Opsional. Jika kosong, laporan memakai nama karyawan yang mengerjakan project ini pada periode laporan."
          />

          <div className="grid grid-cols-1 gap-x-4 sm:grid-cols-2">
            <div className="mb-6">
              <label htmlFor="project-status" className="caption-uppercase block mb-2">
                Status
              </label>
              <select
                id="project-status"
                value={formData.status}
                onChange={(e) => handleStatusChange(e.target.value)}
                className="input-field"
              >
                {Object.entries(PROJECT_STATUS_LABELS).map(([value, label]) => (
                  <option key={value} value={value}>{label}</option>
                ))}
              </select>
            </div>

            <div className="mb-6">
              <label htmlFor="project-progress" className="caption-uppercase block mb-2">
                Progress
              </label>
              <select
                id="project-progress"
                value={formData.progress}
                onChange={(e) => setFormData({ ...formData, progress: Number(e.target.value) })}
                className="input-field"
              >
                {PROJECT_PROGRESS_OPTIONS.map((progress) => (
                  <option key={progress} value={progress}>{progress}%</option>
                ))}
              </select>
            </div>
          </div>

          <Input
            label="Catatan"
            value={formData.note}
            onChange={(e) => setFormData({ ...formData, note: e.target.value })}
            placeholder="contoh: Proses QA"
            maxLength={255}
            helperText="Opsional. Tampil di kolom NOTE laporan; jika kosong dipakai status project."
          />

          <label className="mb-6 flex cursor-pointer items-start gap-2">
            <input
              type="checkbox"
              checked={formData.is_active}
              onChange={(e) => setFormData({ ...formData, is_active: e.target.checked })}
              className="mt-1 h-4 w-4 rounded-sm accent-primary"
            />
            <span className="font-serif text-sm text-body">
              Aktif: bisa dipilih karyawan saat check-in
            </span>
          </label>

          <div className="flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
            <Button type="button" variant="secondary" onClick={handleCloseModal}>
              Batal
            </Button>
            <Button type="submit" disabled={submitting}>
              {submitting ? 'Menyimpan...' : editingProject ? 'Perbarui Project' : 'Buat Project'}
            </Button>
          </div>
        </form>
      </Modal>
    </MainLayout>
  );
};
