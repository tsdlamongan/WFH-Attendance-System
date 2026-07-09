import { useState, useEffect } from 'react';
import { MainLayout } from '../../components/layout/MainLayout';
import { Card } from '../../components/common/Card';
import { Button } from '../../components/common/Button';
import { Input } from '../../components/common/Input';
import { Loading } from '../../components/common/Loading';
import { Modal } from '../../components/common/Modal';
import { Pagination } from '../../components/common/Pagination';
import { getAllAttendances, createAttendance, editAttendance, deleteAttendance, updateTask, searchUsers } from '../../api/manager.api';
import { formatDate, formatTime, formatHours, getMonthStart, getMonthEnd, getTodayDate, formatDateTimeForInput, formatDateForInput } from '../../utils/dateHelpers';
import { usePageTitle } from '../../hooks/usePageTitle';
import { Clock, Edit, Trash2, Calendar, User, Plus } from 'lucide-react';
import toast from 'react-hot-toast';

const isStandbyAttendance = (attendance) =>
  attendance.tasks?.some(
    (t) => String(t?.title ?? '').trim().toLowerCase() === 'standby'
  );

export const AttendanceManagement = () => {
  usePageTitle('Absensi');
  const [loading, setLoading] = useState(true);
  const [attendances, setAttendances] = useState([]);
  const [users, setUsers] = useState([]);
  const [showAddModal, setShowAddModal] = useState(false);
  const [showEditModal, setShowEditModal] = useState(false);
  const [showDeleteModal, setShowDeleteModal] = useState(false);
  const [showTaskModal, setShowTaskModal] = useState(false);
  const [editingAttendance, setEditingAttendance] = useState(null);
  const [submitting, setSubmitting] = useState(false);
  const [startDate, setStartDate] = useState(getMonthStart());
  const [endDate, setEndDate] = useState(getMonthEnd());
  const [selectedUserId, setSelectedUserId] = useState('');
  const [selectedUserName, setSelectedUserName] = useState('');
  const [userSearchQuery, setUserSearchQuery] = useState('');
  const [showUserDropdown, setShowUserDropdown] = useState(false);
  const [searchingUsers, setSearchingUsers] = useState(false);
  const [pagination, setPagination] = useState({
    current_page: 1,
    last_page: 1,
    per_page: 10,
    total: 0,
    from: 0,
    to: 0,
  });

  const [editFormData, setEditFormData] = useState({
    date: '',
    check_in: '',
    check_out: '',
    reason: '',
  });

  const [deleteReason, setDeleteReason] = useState('');

  const [addFormData, setAddFormData] = useState({
    userId: '',
    userName: '',
    date: '',
    check_in: '',
    check_out: '',
    tasks: [{ title: '' }],
    reason: '',
  });
  const [addFormUserSearchQuery, setAddFormUserSearchQuery] = useState('');
  const [addFormUsers, setAddFormUsers] = useState([]);
  const [addFormShowUserDropdown, setAddFormShowUserDropdown] = useState(false);
  const [addFormSearchingUsers, setAddFormSearchingUsers] = useState(false);

  const [editingTasks, setEditingTasks] = useState([]);

  useEffect(() => {
    fetchAttendances(1, 10);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  // Debounce user search
  useEffect(() => {
    if (userSearchQuery.length < 2) {
      setUsers([]);
      return;
    }

    const timeoutId = setTimeout(() => {
      handleUserSearch(userSearchQuery);
    }, 300);

    return () => clearTimeout(timeoutId);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [userSearchQuery]);

  // Close dropdown when clicking outside
  useEffect(() => {
    const handleClickOutside = (event) => {
      if (showUserDropdown && !event.target.closest('.user-search-container')) {
        setShowUserDropdown(false);
      }
      if (addFormShowUserDropdown && !event.target.closest('.add-form-user-search-container')) {
        setAddFormShowUserDropdown(false);
      }
    };

    document.addEventListener('mousedown', handleClickOutside);
    return () => document.removeEventListener('mousedown', handleClickOutside);
  }, [showUserDropdown, addFormShowUserDropdown]);

  // Debounce add-form user search
  useEffect(() => {
    if (!showAddModal || addFormUserSearchQuery.length < 2) {
      setAddFormUsers([]);
      return;
    }
    const timeoutId = setTimeout(async () => {
      try {
        setAddFormSearchingUsers(true);
        const response = await searchUsers(addFormUserSearchQuery, 10);
        if (response.success) setAddFormUsers(response.data);
      } catch (e) {
        setAddFormUsers([]);
      } finally {
        setAddFormSearchingUsers(false);
      }
    }, 300);
    return () => clearTimeout(timeoutId);
  }, [showAddModal, addFormUserSearchQuery]);

  const handleUserSearch = async (query) => {
    try {
      setSearchingUsers(true);
      const response = await searchUsers(query, 10);
      if (response.success) {
        setUsers(response.data);
      }
    } catch (error) {
      console.error('Error searching users:', error);
    } finally {
      setSearchingUsers(false);
    }
  };

  const handleSelectUser = (user) => {
    setSelectedUserId(user.id);
    setSelectedUserName(user.name);
    setUserSearchQuery(user.name);
    setShowUserDropdown(false);
  };

  const handleClearUserSelection = () => {
    setSelectedUserId('');
    setSelectedUserName('');
    setUserSearchQuery('');
    setUsers([]);
  };

  const fetchAttendances = async (page = 1, perPage = 10) => {
    try {
      setLoading(true);
      const userId = selectedUserId || null;
      const response = await getAllAttendances(startDate, endDate, page, perPage, userId);

      if (response.success) {
        setAttendances(response.data);
        if (response.pagination) {
          setPagination(response.pagination);
        }
      }
    } catch (error) {
      console.error('Error fetching attendances:', error);
      toast.error('Failed to fetch attendances');
    } finally {
      setLoading(false);
    }
  };

  const handleFilter = () => {
    fetchAttendances(1, pagination.per_page);
  };

  const handleClearFilter = () => {
    setStartDate(getMonthStart());
    setEndDate(getMonthEnd());
    handleClearUserSelection();
    setTimeout(() => {
      fetchAttendances(1, pagination.per_page);
    }, 100);
  };

  const handlePageChange = (page) => {
    fetchAttendances(page, pagination.per_page);
  };

  const handlePerPageChange = (perPage) => {
    fetchAttendances(1, perPage);
  };

  const handleOpenAddModal = () => {
    setAddFormData({
      userId: '',
      userName: '',
      date: getTodayDate(),
      check_in: '',
      check_out: '',
      tasks: [{ title: '' }],
      reason: '',
    });
    setAddFormUserSearchQuery('');
    setAddFormUsers([]);
    setAddFormShowUserDropdown(false);
    setShowAddModal(true);
  };

  const handleCloseAddModal = () => {
    setShowAddModal(false);
    setAddFormData({ userId: '', userName: '', date: '', check_in: '', check_out: '', tasks: [{ title: '' }], reason: '' });
  };

  const addFormAddTask = () => {
    if (addFormData.tasks.length >= 20) return;
    setAddFormData((prev) => ({ ...prev, tasks: [...prev.tasks, { title: '' }] }));
  };

  const addFormRemoveTask = (index) => {
    if (addFormData.tasks.length <= 1) return;
    setAddFormData((prev) => ({
      ...prev,
      tasks: prev.tasks.filter((_, i) => i !== index),
    }));
  };

  const addFormUpdateTask = (index, value) => {
    setAddFormData((prev) => ({
      ...prev,
      tasks: prev.tasks.map((t, i) => (i === index ? { ...t, title: value } : t)),
    }));
  };

  const handleAddFormSelectUser = (user) => {
    setAddFormData((prev) => ({ ...prev, userId: user.id, userName: user.name }));
    setAddFormUserSearchQuery(user.name);
    setAddFormShowUserDropdown(false);
  };

  const handleAddSubmit = async (e) => {
    e.preventDefault();
    const validTasks = addFormData.tasks.filter((t) => String(t.title || '').trim() !== '');
    if (
      !addFormData.userId ||
      !addFormData.date ||
      !addFormData.check_in ||
      validTasks.length === 0 ||
      !addFormData.reason ||
      addFormData.reason.length < 10
    ) {
      toast.error('Isi karyawan, tanggal, check-in, minimal satu tugas, dan alasan (min. 10 karakter).');
      return;
    }
    try {
      setSubmitting(true);
      const response = await createAttendance({
        userId: addFormData.userId,
        date: addFormData.date,
        checkIn: addFormData.check_in,
        checkOut: addFormData.check_out || null,
        tasks: validTasks.map((t) => ({ title: String(t.title).trim() })),
        reason: addFormData.reason,
      });
      if (response.success) {
        toast.success(response.message || 'Absensi berhasil ditambahkan');
        handleCloseAddModal();
        fetchAttendances(pagination.current_page, pagination.per_page);
      }
    } catch (error) {
      const msg = error.response?.data?.message || error.response?.data?.errors
        ? Object.values(error.response.data.errors || {}).flat().join(', ')
        : 'Gagal menambah absensi';
      toast.error(msg);
    } finally {
      setSubmitting(false);
    }
  };

  const handleOpenEditModal = (attendance) => {
    setEditingAttendance(attendance);
    setEditFormData({
      date: attendance.date ? formatDateForInput(attendance.date) : '',
      check_in: attendance.check_in ? formatDateTimeForInput(attendance.check_in) : '',
      check_out: attendance.check_out ? formatDateTimeForInput(attendance.check_out) : '',
      reason: '',
    });
    setShowEditModal(true);
  };

  const handleCloseEditModal = () => {
    setShowEditModal(false);
    setEditingAttendance(null);
    setEditFormData({
      date: '',
      check_in: '',
      check_out: '',
      reason: '',
    });
  };

  const handleOpenDeleteModal = (attendance) => {
    setEditingAttendance(attendance);
    setDeleteReason('');
    setShowDeleteModal(true);
  };

  const handleCloseDeleteModal = () => {
    setShowDeleteModal(false);
    setEditingAttendance(null);
    setDeleteReason('');
  };

  const handleOpenTaskModal = (attendance) => {
    setEditingAttendance(attendance);
    setEditingTasks(attendance.tasks?.map(task => ({
      id: task.id,
      title: task.title,
      is_completed: task.is_completed,
      blocker_reason: task.blocker_reason || '',
    })) || []);
    setShowTaskModal(true);
  };

  const handleCloseTaskModal = () => {
    setShowTaskModal(false);
    setEditingAttendance(null);
    setEditingTasks([]);
  };

  const handleTaskChange = (index, field, value) => {
    const updatedTasks = [...editingTasks];
    updatedTasks[index][field] = value;
    
    // Clear blocker reason if task is marked as completed
    if (field === 'is_completed' && value === true) {
      updatedTasks[index].blocker_reason = '';
    }
    
    setEditingTasks(updatedTasks);
  };

  const handleEditSubmit = async (e) => {
    e.preventDefault();

    if (!editFormData.reason || editFormData.reason.length < 10) {
      toast.error('Alasan wajib diisi (minimal 10 karakter)');
      return;
    }

    if (!editFormData.date) {
      toast.error('Tanggal wajib diisi');
      return;
    }

    try {
      setSubmitting(true);
      const response = await editAttendance(
        editingAttendance.id,
        editFormData.date,
        editFormData.check_in,
        editFormData.check_out,
        editFormData.reason
      );

      if (response.success) {
        toast.success(response.message);
        handleCloseEditModal();
        fetchAttendances();
      }
    } catch (error) {
      const message = error.response?.data?.message || 'Failed to update attendance';
      toast.error(message);
    } finally {
      setSubmitting(false);
    }
  };

  const handleDeleteSubmit = async (e) => {
    e.preventDefault();

    if (!deleteReason || deleteReason.length < 10) {
      toast.error('Alasan wajib diisi (minimal 10 karakter)');
      return;
    }

    try {
      setSubmitting(true);
      const response = await deleteAttendance(editingAttendance.id, deleteReason);

      if (response.success) {
        toast.success(response.message);
        handleCloseDeleteModal();
        fetchAttendances();
      }
    } catch (error) {
      const message = error.response?.data?.message || 'Failed to delete attendance';
      toast.error(message);
    } finally {
      setSubmitting(false);
    }
  };

  const handleTaskSubmit = async (e) => {
    e.preventDefault();

    // Validate incomplete tasks have blocker reasons
    const invalidTasks = editingTasks.filter(
      task => !task.is_completed && (!task.blocker_reason || task.blocker_reason.trim().length === 0)
    );

    if (invalidTasks.length > 0) {
      toast.error('Tugas yang belum selesai harus memiliki alasan blocker');
      return;
    }

    try {
      setSubmitting(true);
      
      // Update each task
      for (const task of editingTasks) {
        await updateTask(task.id, {
          is_completed: task.is_completed,
          blocker_reason: task.is_completed ? null : task.blocker_reason,
        });
      }

      toast.success('Tugas berhasil diperbarui');
      handleCloseTaskModal();
      fetchAttendances(pagination.current_page, pagination.per_page);
    } catch (error) {
      const message = error.response?.data?.message || 'Gagal memperbarui tugas';
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
        <div>
          <h1 className="text-display-md sm:text-display-lg">Manajemen Absensi</h1>
          <p className="mt-2 font-serif text-body">Kelola semua catatan absensi karyawan</p>
        </div>

        {/* Filters */}
        <Card>
          <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
            <div className="relative user-search-container">
              <label className="caption-uppercase block mb-2">
                Karyawan
              </label>
              <div className="relative">
                <input
                  type="text"
                  value={userSearchQuery}
                  onChange={(e) => {
                    setUserSearchQuery(e.target.value);
                    setShowUserDropdown(true);
                  }}
                  onFocus={() => setShowUserDropdown(true)}
                  placeholder="Ketik nama karyawan..."
                  className="input-field w-full pr-10"
                />
                {selectedUserId && (
                  <button
                    type="button"
                    onClick={handleClearUserSelection}
                    className="absolute right-2 top-1/2 -translate-y-1/2 text-muted hover:text-ink transition-colors"
                  >
                    ✕
                  </button>
                )}
              </div>
              
              {/* Dropdown */}
              {showUserDropdown && userSearchQuery.length >= 2 && (
                <div className="absolute z-10 w-full mt-1 bg-white rounded-xl border-2 border-ink shadow-brutal max-h-60 overflow-y-auto">
                  {searchingUsers ? (
                    <div className="px-4 py-3 font-serif text-sm text-muted">
                      Mencari...
                    </div>
                  ) : users.length > 0 ? (
                    users.map((user) => (
                      <button
                        key={user.id}
                        type="button"
                        onClick={() => handleSelectUser(user)}
                        className="w-full px-4 py-2 text-left hover:bg-ink/10 focus:bg-ink/10 focus:outline-none transition-colors"
                      >
                        <div className="font-serif text-body-strong">{user.name}</div>
                        <div className="font-serif text-sm text-muted">
                          {user.role === 'manager' ? 'Manager' : 'Karyawan'} - {user.email}
                        </div>
                      </button>
                    ))
                  ) : (
                    <div className="px-4 py-3 font-serif text-sm text-muted">
                      Tidak ada hasil
                    </div>
                  )}
                </div>
              )}
            </div>
            <div>
              <label className="caption-uppercase block mb-2">
                Tanggal Mulai
              </label>
              <input
                type="date"
                value={startDate}
                onChange={(e) => setStartDate(e.target.value)}
                className="input-field w-full"
              />
            </div>
            <div>
              <label className="caption-uppercase block mb-2">
                Tanggal Akhir
              </label>
              <input
                type="date"
                value={endDate}
                onChange={(e) => setEndDate(e.target.value)}
                className="input-field w-full"
              />
            </div>
          </div>
          
          <div className="flex flex-wrap items-center gap-3 mt-6">
            <Button onClick={handleFilter}>
              Terapkan Filter
            </Button>
            <Button onClick={handleClearFilter} variant="secondary">
              Hapus Filter
            </Button>
            {selectedUserName && (
              <span className="font-serif text-sm text-muted">
                Filter: <span className="text-body-strong">{selectedUserName}</span>
              </span>
            )}
          </div>
        </Card>

        {/* Attendances List */}
        <Card>
          <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 mb-6">
            <h2 className="text-display-sm">Daftar Absensi</h2>
            <Button onClick={handleOpenAddModal}>
              <Plus size={18} className="inline mr-2" />
              Tambah Absensi
            </Button>
          </div>
          {attendances.length === 0 ? (
            <div className="caption-uppercase text-center py-16">
              Tidak ada catatan absensi ditemukan untuk periode yang dipilih
            </div>
          ) : (
            <div className="rounded-xl border-2 border-ink overflow-hidden">
              <div className="overflow-x-auto">
                <table className="w-full">
                  <thead>
                    <tr className="bg-surface-soft border-b-2 border-ink">
                    <th className="caption-uppercase text-left px-4 py-3">
                      Karyawan
                    </th>
                    <th className="caption-uppercase text-left px-4 py-3">
                      Tanggal
                    </th>
                    <th className="caption-uppercase text-left px-4 py-3">
                      Check In
                    </th>
                    <th className="caption-uppercase text-left px-4 py-3">
                      Check Out
                    </th>
                    <th className="caption-uppercase text-left px-4 py-3">
                      Total Jam
                    </th>
                    <th className="caption-uppercase text-left px-4 py-3">
                      Tugas
                    </th>
                    <th className="caption-uppercase text-right px-4 py-3">
                      Aksi
                    </th>
                  </tr>
                </thead>
                <tbody>
                  {attendances.map((attendance) => (
                    <tr key={attendance.id} className="border-b border-hairline last:border-0 hover:bg-surface-soft transition-colors">
                      <td className="px-4 py-3 whitespace-nowrap">
                        <div className="flex items-center">
                          <div className="flex-shrink-0 h-10 w-10 rounded-xl border-2 border-ink flex items-center justify-center">
                            <User className="text-muted" size={20} />
                          </div>
                          <div className="ml-4">
                            <div className="flex items-center gap-2">
                              <span className="font-serif text-sm text-body-strong">
                                {attendance.user?.name || 'N/A'}
                              </span>
                              {isStandbyAttendance(attendance) && (
                                <span className="badge badge-warning">
                                  Standby
                                </span>
                              )}
                            </div>
                            <div className="font-serif text-sm text-muted">
                              {attendance.user?.email || 'N/A'}
                            </div>
                          </div>
                        </div>
                      </td>
                      <td className="px-4 py-3 whitespace-nowrap font-mono text-sm text-body">
                        {formatDate(attendance.date)}
                      </td>
                      <td className="px-4 py-3 whitespace-nowrap font-mono text-sm text-body">
                        {formatTime(attendance.check_in)}
                      </td>
                      <td className="px-4 py-3 whitespace-nowrap font-mono text-sm text-body">
                        {attendance.check_out ? formatTime(attendance.check_out) : '-'}
                      </td>
                      <td className="px-4 py-3 whitespace-nowrap font-mono text-sm text-body">
                        {formatHours(attendance.total_hours)}
                      </td>
                      <td className="px-4 py-3 whitespace-nowrap font-mono text-sm text-body">
                        <button
                          onClick={() => handleOpenTaskModal(attendance)}
                          className="flex flex-col gap-1 items-start hover:bg-surface-soft p-2 rounded-xl transition-colors w-full text-left"
                          title="Klik untuk edit tugas"
                        >
                          <span className="badge badge-success">
                            ✓ {attendance.tasks?.filter(t => t.is_completed).length || 0} selesai
                          </span>
                          <span className="badge badge-danger">
                            ✗ {attendance.tasks?.filter(t => !t.is_completed).length || 0} belum selesai
                          </span>
                        </button>
                      </td>
                      <td className="px-4 py-3 whitespace-nowrap text-right">
                        <div className="flex items-center justify-end space-x-2">
                          <button
                            onClick={() => handleOpenEditModal(attendance)}
                            className="p-2 text-muted hover:text-ink transition-colors"
                            title="Edit"
                          >
                            <Edit size={18} />
                          </button>
                          <button
                            onClick={() => handleOpenDeleteModal(attendance)}
                            className="p-2 text-muted hover:text-error transition-colors"
                            title="Hapus"
                          >
                            <Trash2 size={18} />
                          </button>
                        </div>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
              </div>
            </div>
          )}

          {attendances.length > 0 && (
            <Pagination
              currentPage={pagination.current_page}
              lastPage={pagination.last_page}
              perPage={pagination.per_page}
              total={pagination.total}
              from={pagination.from}
              to={pagination.to}
              onPageChange={handlePageChange}
              onPerPageChange={handlePerPageChange}
            />
          )}
        </Card>

        {/* Add Attendance Modal */}
        <Modal
          isOpen={showAddModal}
          onClose={handleCloseAddModal}
          title="Tambah Absensi"
        >
          <form onSubmit={handleAddSubmit} className="space-y-4">
            <div className="add-form-user-search-container relative">
              <label className="caption-uppercase block mb-2">
                Karyawan <span className="text-error">*</span>
              </label>
              <input
                type="text"
                value={addFormUserSearchQuery}
                onChange={(e) => {
                  setAddFormUserSearchQuery(e.target.value);
                  if (!e.target.value) setAddFormData((prev) => ({ ...prev, userId: '', userName: '' }));
                  setAddFormShowUserDropdown(e.target.value.length >= 2);
                }}
                onFocus={() => addFormUserSearchQuery.length >= 2 && setAddFormShowUserDropdown(true)}
                placeholder="Ketik nama karyawan (min. 2 karakter)"
                className="input-field w-full pr-8"
              />
              {addFormData.userName && (
                <button
                  type="button"
                  onClick={() => {
                    setAddFormData((prev) => ({ ...prev, userId: '', userName: '' }));
                    setAddFormUserSearchQuery('');
                  }}
                  className="absolute right-2 top-9 text-muted hover:text-ink transition-colors"
                >
                  ✕
                </button>
              )}
              {addFormShowUserDropdown && addFormUserSearchQuery.length >= 2 && (
                <div className="absolute z-10 w-full mt-1 bg-white rounded-xl border-2 border-ink shadow-brutal max-h-48 overflow-y-auto">
                  {addFormSearchingUsers ? (
                    <div className="px-4 py-3 font-serif text-sm text-muted">Mencari...</div>
                  ) : addFormUsers.length > 0 ? (
                    addFormUsers.map((u) => (
                      <button
                        key={u.id}
                        type="button"
                        onClick={() => handleAddFormSelectUser(u)}
                        className="w-full px-4 py-2 text-left hover:bg-ink/10 focus:bg-ink/10 focus:outline-none transition-colors"
                      >
                        <div className="font-serif text-body-strong">{u.name}</div>
                        <div className="font-serif text-sm text-muted">{u.email}</div>
                      </button>
                    ))
                  ) : (
                    <div className="px-4 py-3 font-serif text-sm text-muted">Tidak ada hasil</div>
                  )}
                </div>
              )}
            </div>

            <div>
              <label className="caption-uppercase block mb-2">
                Tanggal <span className="text-error">*</span>
              </label>
              <input
                type="date"
                value={addFormData.date}
                onChange={(e) => setAddFormData((prev) => ({ ...prev, date: e.target.value }))}
                className="input-field w-full"
                required
              />
            </div>

            <div>
              <label className="caption-uppercase block mb-2">
                Check In <span className="text-error">*</span>
              </label>
              <input
                type="datetime-local"
                value={addFormData.check_in}
                onChange={(e) => setAddFormData((prev) => ({ ...prev, check_in: e.target.value }))}
                className="input-field w-full"
                required
              />
              <p className="mt-2 font-serif text-sm text-muted">
                Tanggal check-in harus sama dengan tanggal absensi
              </p>
            </div>

            <div>
              <label className="caption-uppercase block mb-2">
                Check Out
              </label>
              <input
                type="datetime-local"
                value={addFormData.check_out}
                onChange={(e) => setAddFormData((prev) => ({ ...prev, check_out: e.target.value }))}
                className="input-field w-full"
              />
            </div>

            <div>
              <label className="caption-uppercase block mb-2">
                Tugas yang dikerjakan <span className="text-error">*</span>
              </label>
              <p className="mb-2 font-serif text-sm text-muted">
                Minimal 1 tugas, maksimal 20. Isi tugas yang dikerjakan karyawan pada sesi ini.
              </p>
              <div className="space-y-2 max-h-48 overflow-y-auto">
                {addFormData.tasks.map((task, index) => (
                  <div key={index} className="flex items-center gap-2">
                    <input
                      type="text"
                      value={task.title}
                      onChange={(e) => addFormUpdateTask(index, e.target.value)}
                      placeholder={`Tugas ${index + 1}`}
                      className="input-field flex-1"
                    />
                    {addFormData.tasks.length > 1 && (
                      <button
                        type="button"
                        onClick={() => addFormRemoveTask(index)}
                        className="p-2 text-muted hover:text-error transition-colors"
                        title="Hapus tugas"
                      >
                        <Trash2 size={18} />
                      </button>
                    )}
                  </div>
                ))}
              </div>
              {addFormData.tasks.length < 20 && (
                <button
                  type="button"
                  onClick={addFormAddTask}
                  className="mt-2 flex items-center gap-2 font-mono text-caption uppercase text-muted hover:text-ink transition-colors"
                >
                  <Plus size={16} />
                  Tambah Tugas ({addFormData.tasks.length}/20)
                </button>
              )}
            </div>

            <div>
              <label className="caption-uppercase block mb-2">
                Alasan <span className="text-error">*</span>
              </label>
              <textarea
                value={addFormData.reason}
                onChange={(e) => setAddFormData((prev) => ({ ...prev, reason: e.target.value }))}
                className="input-field w-full"
                rows={3}
                placeholder="Alasan menambah absensi (minimal 10 karakter)"
                required
                minLength={10}
              />
            </div>

            <div className="flex flex-wrap justify-end gap-3 pt-4">
              <Button type="button" variant="secondary" onClick={handleCloseAddModal} disabled={submitting}>
                Batal
              </Button>
              <Button type="submit" disabled={submitting}>
                {submitting ? 'Menyimpan...' : 'Simpan'}
              </Button>
            </div>
          </form>
        </Modal>

        {/* Edit Modal */}
        <Modal
          isOpen={showEditModal}
          onClose={handleCloseEditModal}
          title="Edit Absensi"
        >
          <form onSubmit={handleEditSubmit} className="space-y-4">
            <div>
              <label className="caption-uppercase block mb-2">
                Karyawan
              </label>
              <div className="input-field text-muted">
                {editingAttendance?.user?.name} ({editingAttendance?.user?.email})
              </div>
            </div>

            <div>
              <label className="caption-uppercase block mb-2">
                Tanggal <span className="text-error">*</span>
              </label>
              <input
                type="date"
                value={editFormData.date}
                onChange={(e) => setEditFormData({ ...editFormData, date: e.target.value })}
                className="input-field w-full"
                required
              />
              <p className="mt-2 font-serif text-sm text-muted">
                Tanggal absensi harus sama dengan tanggal check-in dan check-out
              </p>
            </div>

            <div>
              <label className="caption-uppercase block mb-2">
                Check In <span className="text-error">*</span>
              </label>
              <input
                type="datetime-local"
                value={editFormData.check_in}
                onChange={(e) => setEditFormData({ ...editFormData, check_in: e.target.value })}
                className="input-field w-full"
                required
              />
            </div>

            <div>
              <label className="caption-uppercase block mb-2">
                Check Out
              </label>
              <input
                type="datetime-local"
                value={editFormData.check_out}
                onChange={(e) => setEditFormData({ ...editFormData, check_out: e.target.value })}
                className="input-field w-full"
              />
            </div>

            <div>
              <label className="caption-uppercase block mb-2">
                Alasan <span className="text-error">*</span>
              </label>
              <textarea
                value={editFormData.reason}
                onChange={(e) => setEditFormData({ ...editFormData, reason: e.target.value })}
                className="input-field w-full"
                rows="3"
                placeholder="Alasan untuk mengedit absensi ini (minimal 10 karakter)"
                required
              />
            </div>

            <div className="flex flex-wrap justify-end gap-3 pt-4">
              <Button
                type="button"
                variant="secondary"
                onClick={handleCloseEditModal}
                disabled={submitting}
              >
                Batal
              </Button>
              <Button
                type="submit"
                disabled={submitting}
              >
                {submitting ? 'Memperbarui...' : 'Perbarui Absensi'}
              </Button>
            </div>
          </form>
        </Modal>

        {/* Delete Modal */}
        <Modal
          isOpen={showDeleteModal}
          onClose={handleCloseDeleteModal}
          title="Hapus Absensi"
        >
          <form onSubmit={handleDeleteSubmit} className="space-y-4">
            <div>
              <p className="font-serif text-sm text-body mb-4">
                Apakah Anda yakin ingin menghapus catatan absensi untuk{' '}
                <strong className="font-normal text-ink">{editingAttendance?.user?.name}</strong>?
              </p>
              <p className="caption-uppercase mb-4">
                Tanggal: {formatDate(editingAttendance?.date)}<br />
                Check In: {formatTime(editingAttendance?.check_in)}<br />
                Check Out: {editingAttendance?.check_out ? formatTime(editingAttendance.check_out) : '-'}
              </p>
            </div>

            <div>
              <label className="caption-uppercase block mb-2">
                Alasan <span className="text-error">*</span>
              </label>
              <textarea
                value={deleteReason}
                onChange={(e) => setDeleteReason(e.target.value)}
                className="input-field w-full"
                rows="3"
                placeholder="Alasan untuk menghapus absensi ini (minimal 10 karakter)"
                required
              />
            </div>

            <div className="flex flex-wrap justify-end gap-3 pt-4">
              <Button
                type="button"
                variant="secondary"
                onClick={handleCloseDeleteModal}
                disabled={submitting}
              >
                Batal
              </Button>
              <Button
                type="submit"
                variant="danger"
                disabled={submitting}
              >
                {submitting ? 'Menghapus...' : 'Hapus Absensi'}
              </Button>
            </div>
          </form>
        </Modal>

        {/* Edit Tasks Modal */}
        <Modal
          isOpen={showTaskModal}
          onClose={handleCloseTaskModal}
          title="Edit Tugas"
          size="lg"
        >
          <form onSubmit={handleTaskSubmit} className="space-y-4">
            <div>
              <p className="font-serif text-sm text-body mb-4">
                Edit status tugas untuk <strong className="font-normal text-ink">{editingAttendance?.user?.name}</strong>
              </p>
              <p className="caption-uppercase mb-4">
                Tanggal: {formatDate(editingAttendance?.date)}<br />
                Total Tugas: {editingTasks.length}
              </p>
            </div>

            <div className="space-y-4 max-h-96 overflow-y-auto">
              {editingTasks.map((task, index) => (
                <div key={task.id} className="rounded-xl border-2 border-ink bg-surface-soft p-4 space-y-3">
                  <div className="flex items-start justify-between">
                    <div className="flex-1">
                      <p className="font-serif text-body-strong">{task.title}</p>
                    </div>
                    <div className="ml-4">
                      <label className="flex items-center space-x-2 cursor-pointer">
                        <input
                          type="checkbox"
                          checked={task.is_completed}
                          onChange={(e) => handleTaskChange(index, 'is_completed', e.target.checked)}
                          className="w-5 h-5 accent-ink"
                        />
                        <span className={`font-mono text-caption uppercase ${task.is_completed ? 'text-success' : 'text-muted'}`}>
                          {task.is_completed ? 'Selesai' : 'Belum Selesai'}
                        </span>
                      </label>
                    </div>
                  </div>

                  {!task.is_completed && (
                    <div>
                      <label className="caption-uppercase block mb-2">
                        Alasan Blocker <span className="text-error">*</span>
                      </label>
                      <textarea
                        value={task.blocker_reason}
                        onChange={(e) => handleTaskChange(index, 'blocker_reason', e.target.value)}
                        className="input-field w-full"
                        rows="2"
                        placeholder="Jelaskan alasan tugas belum selesai"
                        required={!task.is_completed}
                      />
                    </div>
                  )}
                </div>
              ))}
            </div>

            <div className="flex flex-wrap justify-end gap-3 pt-4 border-t border-hairline">
              <Button
                type="button"
                variant="secondary"
                onClick={handleCloseTaskModal}
                disabled={submitting}
              >
                Batal
              </Button>
              <Button
                type="submit"
                disabled={submitting}
              >
                {submitting ? 'Menyimpan...' : 'Simpan Perubahan'}
              </Button>
            </div>
          </form>
        </Modal>
      </div>
    </MainLayout>
  );
};
