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
      toast.error('Reason is required (minimum 10 characters)');
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
      <div className="space-y-6">
        {/* Header */}
        <div>
          <h1 className="text-3xl font-bold text-gray-900">Manajemen Absensi</h1>
          <p className="text-gray-600 mt-1">Kelola semua catatan absensi karyawan</p>
        </div>

        {/* Filters */}
        <Card>
          <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
            <div className="relative user-search-container">
              <label className="block text-sm font-medium text-gray-700 mb-2">
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
                    className="absolute right-2 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-600"
                  >
                    ✕
                  </button>
                )}
              </div>
              
              {/* Dropdown */}
              {showUserDropdown && userSearchQuery.length >= 2 && (
                <div className="absolute z-10 w-full mt-1 bg-white border border-gray-300 rounded-lg shadow-lg max-h-60 overflow-y-auto">
                  {searchingUsers ? (
                    <div className="px-4 py-3 text-sm text-gray-500">
                      Mencari...
                    </div>
                  ) : users.length > 0 ? (
                    users.map((user) => (
                      <button
                        key={user.id}
                        type="button"
                        onClick={() => handleSelectUser(user)}
                        className="w-full px-4 py-2 text-left hover:bg-gray-100 focus:bg-gray-100 focus:outline-none"
                      >
                        <div className="font-medium text-gray-900">{user.name}</div>
                        <div className="text-sm text-gray-500">
                          {user.role === 'manager' ? 'Manager' : 'Karyawan'} - {user.email}
                        </div>
                      </button>
                    ))
                  ) : (
                    <div className="px-4 py-3 text-sm text-gray-500">
                      Tidak ada hasil
                    </div>
                  )}
                </div>
              )}
            </div>
            <div>
              <label className="block text-sm font-medium text-gray-700 mb-2">
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
              <label className="block text-sm font-medium text-gray-700 mb-2">
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
          
          <div className="flex items-center space-x-3 mt-4">
            <Button onClick={handleFilter}>
              Terapkan Filter
            </Button>
            <Button onClick={handleClearFilter} variant="secondary">
              Hapus Filter
            </Button>
            {selectedUserName && (
              <span className="text-sm text-gray-600">
                Filter: <span className="font-medium">{selectedUserName}</span>
              </span>
            )}
          </div>
        </Card>

        {/* Attendances List */}
        <Card>
          <div className="flex justify-between items-center mb-4">
            <h2 className="text-lg font-semibold text-gray-900">Daftar Absensi</h2>
            <Button onClick={handleOpenAddModal}>
              <Plus size={18} className="inline mr-2" />
              Tambah Absensi
            </Button>
          </div>
          {attendances.length === 0 ? (
            <div className="text-center py-8 text-gray-500">
              Tidak ada catatan absensi ditemukan untuk periode yang dipilih
            </div>
          ) : (
            <div className="overflow-x-auto">
              <table className="min-w-full divide-y divide-gray-200">
                <thead className="bg-gray-50">
                  <tr>
                    <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                      Karyawan
                    </th>
                    <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                      Tanggal
                    </th>
                    <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                      Check In
                    </th>
                    <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                      Check Out
                    </th>
                    <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                      Total Jam
                    </th>
                    <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                      Tugas
                    </th>
                    <th className="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">
                      Aksi
                    </th>
                  </tr>
                </thead>
                <tbody className="bg-white divide-y divide-gray-200">
                  {attendances.map((attendance) => (
                    <tr key={attendance.id} className="hover:bg-gray-50">
                      <td className="px-6 py-4 whitespace-nowrap">
                        <div className="flex items-center">
                          <div className="flex-shrink-0 h-10 w-10 rounded-full bg-primary-100 flex items-center justify-center">
                            <User className="text-primary-600" size={20} />
                          </div>
                          <div className="ml-4">
                            <div className="flex items-center gap-2">
                              <span className="text-sm font-medium text-gray-900">
                                {attendance.user?.name || 'N/A'}
                              </span>
                              {isStandbyAttendance(attendance) && (
                                <span className="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-amber-100 text-amber-800">
                                  Standby
                                </span>
                              )}
                            </div>
                            <div className="text-sm text-gray-500">
                              {attendance.user?.email || 'N/A'}
                            </div>
                          </div>
                        </div>
                      </td>
                      <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                        {formatDate(attendance.date)}
                      </td>
                      <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                        {formatTime(attendance.check_in)}
                      </td>
                      <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                        {attendance.check_out ? formatTime(attendance.check_out) : '-'}
                      </td>
                      <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                        {formatHours(attendance.total_hours)}
                      </td>
                      <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                        <button
                          onClick={() => handleOpenTaskModal(attendance)}
                          className="flex flex-col hover:bg-gray-50 p-2 rounded transition-colors w-full text-left"
                          title="Klik untuk edit tugas"
                        >
                          <span className="text-green-600">
                            ✓ {attendance.tasks?.filter(t => t.is_completed).length || 0} selesai
                          </span>
                          <span className="text-red-600">
                            ✗ {attendance.tasks?.filter(t => !t.is_completed).length || 0} belum selesai
                          </span>
                        </button>
                      </td>
                      <td className="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                        <div className="flex items-center justify-end space-x-2">
                          <button
                            onClick={() => handleOpenEditModal(attendance)}
                            className="text-primary-600 hover:text-primary-900 p-2 hover:bg-primary-50 rounded"
                            title="Edit"
                          >
                            <Edit size={18} />
                          </button>
                          <button
                            onClick={() => handleOpenDeleteModal(attendance)}
                            className="text-red-600 hover:text-red-900 p-2 hover:bg-red-50 rounded"
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
              <label className="block text-sm font-medium text-gray-700 mb-2">
                Karyawan <span className="text-red-500">*</span>
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
                  className="absolute right-2 top-9 text-gray-400 hover:text-gray-600"
                >
                  ✕
                </button>
              )}
              {addFormShowUserDropdown && addFormUserSearchQuery.length >= 2 && (
                <div className="absolute z-10 w-full mt-1 bg-white border border-gray-300 rounded-lg shadow-lg max-h-48 overflow-y-auto">
                  {addFormSearchingUsers ? (
                    <div className="px-4 py-3 text-sm text-gray-500">Mencari...</div>
                  ) : addFormUsers.length > 0 ? (
                    addFormUsers.map((u) => (
                      <button
                        key={u.id}
                        type="button"
                        onClick={() => handleAddFormSelectUser(u)}
                        className="w-full px-4 py-2 text-left hover:bg-gray-100 focus:bg-gray-100 focus:outline-none"
                      >
                        <div className="font-medium text-gray-900">{u.name}</div>
                        <div className="text-sm text-gray-500">{u.email}</div>
                      </button>
                    ))
                  ) : (
                    <div className="px-4 py-3 text-sm text-gray-500">Tidak ada hasil</div>
                  )}
                </div>
              )}
            </div>

            <div>
              <label className="block text-sm font-medium text-gray-700 mb-2">
                Tanggal <span className="text-red-500">*</span>
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
              <label className="block text-sm font-medium text-gray-700 mb-2">
                Check In <span className="text-red-500">*</span>
              </label>
              <input
                type="datetime-local"
                value={addFormData.check_in}
                onChange={(e) => setAddFormData((prev) => ({ ...prev, check_in: e.target.value }))}
                className="input-field w-full"
                required
              />
              <p className="text-xs text-gray-500 mt-1">
                Tanggal check-in harus sama dengan tanggal absensi
              </p>
            </div>

            <div>
              <label className="block text-sm font-medium text-gray-700 mb-2">
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
              <label className="block text-sm font-medium text-gray-700 mb-2">
                Tugas yang dikerjakan <span className="text-red-500">*</span>
              </label>
              <p className="text-xs text-gray-500 mb-2">
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
                        className="p-2 text-red-600 hover:bg-red-50 rounded"
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
                  className="mt-2 flex items-center gap-2 text-sm text-primary-600 hover:text-primary-700"
                >
                  <Plus size={16} />
                  Tambah Tugas ({addFormData.tasks.length}/20)
                </button>
              )}
            </div>

            <div>
              <label className="block text-sm font-medium text-gray-700 mb-2">
                Alasan <span className="text-red-500">*</span>
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

            <div className="flex justify-end space-x-3 pt-4">
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
              <label className="block text-sm font-medium text-gray-700 mb-2">
                Karyawan
              </label>
              <div className="input-field bg-gray-100">
                {editingAttendance?.user?.name} ({editingAttendance?.user?.email})
              </div>
            </div>

            <div>
              <label className="block text-sm font-medium text-gray-700 mb-2">
                Tanggal <span className="text-red-500">*</span>
              </label>
              <input
                type="date"
                value={editFormData.date}
                onChange={(e) => setEditFormData({ ...editFormData, date: e.target.value })}
                className="input-field w-full"
                required
              />
              <p className="text-xs text-gray-500 mt-1">
                Tanggal absensi harus sama dengan tanggal check-in dan check-out
              </p>
            </div>

            <div>
              <label className="block text-sm font-medium text-gray-700 mb-2">
                Check In <span className="text-red-500">*</span>
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
              <label className="block text-sm font-medium text-gray-700 mb-2">
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
              <label className="block text-sm font-medium text-gray-700 mb-2">
                Alasan <span className="text-red-500">*</span>
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

            <div className="flex justify-end space-x-3 pt-4">
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
              <p className="text-sm text-gray-600 mb-4">
                Apakah Anda yakin ingin menghapus catatan absensi untuk{' '}
                <strong>{editingAttendance?.user?.name}</strong>?
              </p>
              <p className="text-xs text-gray-500 mb-4">
                Tanggal: {formatDate(editingAttendance?.date)}<br />
                Check In: {formatTime(editingAttendance?.check_in)}<br />
                Check Out: {editingAttendance?.check_out ? formatTime(editingAttendance.check_out) : '-'}
              </p>
            </div>

            <div>
              <label className="block text-sm font-medium text-gray-700 mb-2">
                Alasan <span className="text-red-500">*</span>
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

            <div className="flex justify-end space-x-3 pt-4">
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
              <p className="text-sm text-gray-600 mb-4">
                Edit status tugas untuk <strong>{editingAttendance?.user?.name}</strong>
              </p>
              <p className="text-xs text-gray-500 mb-4">
                Tanggal: {formatDate(editingAttendance?.date)}<br />
                Total Tugas: {editingTasks.length}
              </p>
            </div>

            <div className="space-y-4 max-h-96 overflow-y-auto">
              {editingTasks.map((task, index) => (
                <div key={task.id} className="border border-gray-200 rounded-lg p-4 space-y-3">
                  <div className="flex items-start justify-between">
                    <div className="flex-1">
                      <p className="font-medium text-gray-900">{task.title}</p>
                    </div>
                    <div className="ml-4">
                      <label className="flex items-center space-x-2 cursor-pointer">
                        <input
                          type="checkbox"
                          checked={task.is_completed}
                          onChange={(e) => handleTaskChange(index, 'is_completed', e.target.checked)}
                          className="w-5 h-5 text-primary-600 border-gray-300 rounded focus:ring-primary-500"
                        />
                        <span className={`text-sm font-medium ${task.is_completed ? 'text-green-600' : 'text-gray-600'}`}>
                          {task.is_completed ? 'Selesai' : 'Belum Selesai'}
                        </span>
                      </label>
                    </div>
                  </div>

                  {!task.is_completed && (
                    <div>
                      <label className="block text-sm font-medium text-gray-700 mb-2">
                        Alasan Blocker <span className="text-red-500">*</span>
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

            <div className="flex justify-end space-x-3 pt-4 border-t">
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
