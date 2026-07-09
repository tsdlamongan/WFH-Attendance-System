import { useState, useEffect } from 'react';
import { MainLayout } from '../../components/layout/MainLayout';
import { Card } from '../../components/common/Card';
import { Button } from '../../components/common/Button';
import { Input } from '../../components/common/Input';
import { Loading } from '../../components/common/Loading';
import { Modal } from '../../components/common/Modal';
import { getHolidays } from '../../api/holiday.api';
import { createHoliday, updateHoliday, deleteHoliday } from '../../api/manager.api';
import { formatDate } from '../../utils/dateHelpers';
import { usePageTitle } from '../../hooks/usePageTitle';
import { Calendar, Plus, Edit, Trash2 } from 'lucide-react';
import toast from 'react-hot-toast';

export const HolidayManagement = () => {
  usePageTitle('Hari Libur');
  const [loading, setLoading] = useState(true);
  const [holidays, setHolidays] = useState([]);
  const [showModal, setShowModal] = useState(false);
  const [editingHoliday, setEditingHoliday] = useState(null);
  const [submitting, setSubmitting] = useState(false);
  const [selectedYear, setSelectedYear] = useState(new Date().getFullYear());
  
  const [formData, setFormData] = useState({
    date: '',
    name: '',
    description: '',
  });

  useEffect(() => {
    fetchHolidays();
  }, [selectedYear]);

  const fetchHolidays = async () => {
    try {
      setLoading(true);
      const response = await getHolidays(selectedYear);
      
      if (response.success) {
        setHolidays(response.data);
      }
    } catch (error) {
      console.error('Error fetching holidays:', error);
      toast.error('Gagal mengambil data hari libur');
    } finally {
      setLoading(false);
    }
  };

  const handleOpenModal = (holiday = null) => {
    if (holiday) {
      setEditingHoliday(holiday);
      setFormData({
        date: holiday.date,
        name: holiday.name,
        description: holiday.description || '',
      });
    } else {
      setEditingHoliday(null);
      setFormData({
        date: '',
        name: '',
        description: '',
      });
    }
    setShowModal(true);
  };

  const handleCloseModal = () => {
    setShowModal(false);
    setEditingHoliday(null);
    setFormData({
      date: '',
      name: '',
      description: '',
    });
  };

  const handleSubmit = async (e) => {
    e.preventDefault();

    try {
      setSubmitting(true);
      
      let response;
      if (editingHoliday) {
        response = await updateHoliday(editingHoliday.id, formData);
      } else {
        response = await createHoliday(formData);
      }
      
      if (response.success) {
        toast.success(response.message);
        handleCloseModal();
        fetchHolidays();
      }
    } catch (error) {
      const message = error.response?.data?.message || 'Operasi gagal';
      toast.error(message);
    } finally {
      setSubmitting(false);
    }
  };

  const handleDelete = async (holidayId, holidayName) => {
    if (!confirm(`Apakah Anda yakin ingin menghapus ${holidayName}?`)) {
      return;
    }

    try {
      const response = await deleteHoliday(holidayId);
      
      if (response.success) {
        toast.success(response.message);
        fetchHolidays();
      }
    } catch (error) {
      const message = error.response?.data?.message || 'Gagal menghapus hari libur';
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
        <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 mb-8">
          <div>
            <h1 className="text-display-md sm:text-display-lg">Manajemen Hari Libur</h1>
            <p className="mt-2 font-serif text-body">Kelola hari libur perusahaan</p>
          </div>
          <div className="flex items-center gap-4">
            <select
              value={selectedYear}
              onChange={(e) => setSelectedYear(Number(e.target.value))}
              className="input-field"
            >
              {[2024, 2025, 2026].map(year => (
                <option key={year} value={year}>{year}</option>
              ))}
            </select>
            <Button
              onClick={() => handleOpenModal()}
              className="flex-shrink-0"
            >
              <Plus size={20} />
              <span>Tambah</span>
            </Button>
          </div>
        </div>

        {/* Holidays List */}
        <Card>
          {holidays.length === 0 ? (
            <div className="text-center py-16">
              <Calendar size={48} className="mx-auto text-muted mb-4" />
              <p className="caption-uppercase">Tidak ada hari libur ditemukan untuk {selectedYear}</p>
              <Button
                onClick={() => handleOpenModal()}
                variant="outline"
                className="mt-6"
              >
                Tambah Hari Libur Pertama
              </Button>
            </div>
          ) : (
            <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4 sm:gap-6">
              {holidays.map((holiday) => (
                <div
                  key={holiday.id}
                  className="rounded-card border-2 border-ink bg-white p-4 shadow-brutal-sm transition-all hover:shadow-brutal"
                >
                  <div className="flex items-start justify-between mb-3">
                    <div className="flex items-start space-x-3">
                      <div className="w-10 h-10 rounded-xl border-2 border-ink bg-accent flex items-center justify-center flex-shrink-0 shadow-brutal-sm">
                        <Calendar size={20} className="text-white" />
                      </div>
                      <div>
                        <p className="font-serif text-body-strong">{holiday.name}</p>
                        <p className="font-mono text-sm text-muted">{formatDate(holiday.date)}</p>
                      </div>
                    </div>
                  </div>

                  {holiday.description && (
                    <p className="font-serif text-sm text-body mb-3">{holiday.description}</p>
                  )}

                  <div className="flex items-center justify-end space-x-2">
                    <button
                      onClick={() => handleOpenModal(holiday)}
                      className="p-2 text-muted hover:text-ink transition-colors"
                    >
                      <Edit size={18} />
                    </button>
                    <button
                      onClick={() => handleDelete(holiday.id, holiday.name)}
                      className="p-2 text-muted hover:text-error transition-colors"
                    >
                      <Trash2 size={18} />
                    </button>
                  </div>
                </div>
              ))}
            </div>
          )}
        </Card>
      </div>

      {/* Add/Edit Holiday Modal */}
      <Modal
        isOpen={showModal}
        onClose={handleCloseModal}
        title={editingHoliday ? 'Edit Hari Libur' : 'Tambah Hari Libur Baru'}
        size="md"
      >
        <form onSubmit={handleSubmit}>
          <Input
            label="Nama Hari Libur"
            value={formData.name}
            onChange={(e) => setFormData({ ...formData, name: e.target.value })}
            placeholder="contoh: Hari Raya Idul Fitri"
            required
          />

          <Input
            label="Tanggal"
            type="date"
            value={formData.date}
            onChange={(e) => setFormData({ ...formData, date: e.target.value })}
            required
          />

          <div className="mb-6">
            <label className="caption-uppercase block mb-2">
              Deskripsi
            </label>
            <textarea
              value={formData.description}
              onChange={(e) => setFormData({ ...formData, description: e.target.value })}
              className="input-field"
              rows="3"
              placeholder="Deskripsi opsional"
            />
          </div>

          <div className="flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
            <Button
              type="button"
              variant="secondary"
              onClick={handleCloseModal}
            >
              Batal
            </Button>
            <Button type="submit" disabled={submitting}>
              {submitting ? 'Menyimpan...' : editingHoliday ? 'Perbarui Hari Libur' : 'Buat Hari Libur'}
            </Button>
          </div>
        </form>
      </Modal>
    </MainLayout>
  );
};
