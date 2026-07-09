import { useState, useRef, useEffect } from 'react';
import { Modal } from '../common/Modal';
import { Button } from '../common/Button';
import { Input } from '../common/Input';
import { Plus, X, ClipboardPaste, AlertCircle, CheckSquare } from 'lucide-react';
import { getIncompleteTasksFromLastSession } from '../../api/task.api';
import toast from 'react-hot-toast';

/** Must match backend Task::STANDBY_TITLE. When present, backend sends WhatsApp standby notification. */
const STANDBY_TASK_TITLE = 'Standby';

const isStandbyTask = (t) =>
  String(t?.title ?? '').trim().toLowerCase() === STANDBY_TASK_TITLE.toLowerCase();

export const CheckInModal = ({ isOpen, onClose, onSubmit, loading }) => {
  const [tasks, setTasks] = useState([{ title: '' }]);
  const [isStandby, setIsStandby] = useState(false);
  const [incompleteTasks, setIncompleteTasks] = useState([]);
  const [loadingIncompleteTasks, setLoadingIncompleteTasks] = useState(false);
  const [showIncompleteTasks, setShowIncompleteTasks] = useState(false);
  const inputRefs = useRef({});

  // Fetch incomplete tasks and reset form when modal opens
  useEffect(() => {
    if (isOpen) {
      setTasks([{ title: '' }]);
      setIsStandby(false);
      inputRefs.current = {};
      fetchIncompleteTasks();
    }
  }, [isOpen]);

  // Sync checkbox with presence of Standby task (e.g. after paste or manual type)
  useEffect(() => {
    if (!isOpen) return;
    const hasStandby = tasks.some(isStandbyTask);
    if (hasStandby !== isStandby) setIsStandby(hasStandby);
  }, [tasks, isOpen]);

  const fetchIncompleteTasks = async () => {
    try {
      setLoadingIncompleteTasks(true);
      const response = await getIncompleteTasksFromLastSession();
      if (response.success && response.data) {
        // Map tasks from last session
        const tasks = response.data.map(task => ({
          title: task.title,
          blocker_reason: task.blocker_reason,
          from_date: task.attendance?.date || null
        }));
        
        setIncompleteTasks(tasks);
        
        // Auto-show if there are incomplete tasks
        if (tasks.length > 0) {
          setShowIncompleteTasks(true);
        }
      }
    } catch (error) {
      console.error('Failed to fetch incomplete tasks:', error);
      // Don't show error toast - it's not critical
    } finally {
      setLoadingIncompleteTasks(false);
    }
  };

  // Focus first input when modal opens
  useEffect(() => {
    if (isOpen) {
      setTimeout(() => {
        if (inputRefs.current[0]) {
          inputRefs.current[0].focus();
        }
      }, 100);
    }
  }, [isOpen]);

  const addTask = (focusIndex = null) => {
    if (tasks.length < 20) {
      setTasks([...tasks, { title: '' }]);
      // Focus on new task after state update
      if (focusIndex !== null) {
        setTimeout(() => {
          const newIndex = focusIndex + 1;
          if (inputRefs.current[newIndex]) {
            inputRefs.current[newIndex].focus();
          }
        }, 50);
      }
    }
  };

  const addIncompleteTask = (incompleteTask) => {
    // Check if task already exists in current tasks
    const exists = tasks.some(t => t.title.trim().toLowerCase() === incompleteTask.title.trim().toLowerCase());
    
    if (exists) {
      toast.error('Tugas ini sudah ada dalam daftar');
      return;
    }
    
    if (tasks.length >= 20) {
      toast.error('Maksimal 20 tugas');
      return;
    }
    
    // Add to tasks list
    // If first task is empty, replace it. Otherwise, add new task
    if (tasks.length === 1 && tasks[0].title.trim() === '') {
      setTasks([{ title: incompleteTask.title }]);
    } else {
      setTasks([...tasks, { title: incompleteTask.title }]);
    }
    
    toast.success(`✅ "${incompleteTask.title}" ditambahkan`);
  };

  const addAllIncompleteTasks = () => {
    const availableSlots = 20 - (tasks.length === 1 && tasks[0].title.trim() === '' ? 0 : tasks.length);
    
    if (availableSlots === 0) {
      toast.error('Tidak ada slot tersisa (maksimal 20 tugas)');
      return;
    }
    
    // Get tasks that don't exist yet
    const newTasks = incompleteTasks.filter(incTask => {
      return !tasks.some(t => t.title.trim().toLowerCase() === incTask.title.trim().toLowerCase());
    }).slice(0, availableSlots);
    
    if (newTasks.length === 0) {
      toast.error('Semua tugas yang belum selesai sudah ada dalam daftar');
      return;
    }
    
    // Add new tasks
    if (tasks.length === 1 && tasks[0].title.trim() === '') {
      setTasks(newTasks.map(t => ({ title: t.title })));
    } else {
      setTasks([...tasks, ...newTasks.map(t => ({ title: t.title }))]);
    }
    
    toast.success(`${newTasks.length} tugas ditambahkan`);
  };

  const removeTask = (index) => {
    if (tasks.length > 1) {
      setTasks(tasks.filter((_, i) => i !== index));
      // Update refs after removal - need to wait for re-render
      setTimeout(() => {
        // Focus on previous input or next input after removal
        const focusIndex = index > 0 ? index - 1 : 0;
        if (inputRefs.current[focusIndex]) {
          inputRefs.current[focusIndex].focus();
        }
      }, 50);
    }
  };

  const updateTask = (index, value) => {
    const newTasks = [...tasks];
    newTasks[index].title = value;
    setTasks(newTasks);
  };

  const handleStandbyChange = (checked) => {
    setIsStandby(checked);
    if (checked) {
      if (tasks.some(isStandbyTask)) return;
      if (tasks.length >= 20) {
        toast.error('Maksimal 20 tugas');
        setIsStandby(false);
        return;
      }
      if (tasks.length === 1 && tasks[0].title.trim() === '') {
        setTasks([{ title: STANDBY_TASK_TITLE }]);
      } else {
        setTasks([...tasks, { title: STANDBY_TASK_TITLE }]);
      }
    } else {
      const withoutStandby = tasks.filter((t) => !isStandbyTask(t));
      setTasks(withoutStandby.length > 0 ? withoutStandby : [{ title: '' }]);
    }
  };

  const handleKeyDown = (e, index) => {
    // Enter = Add new task (unless it's the last empty task)
    if (e.key === 'Enter' && !e.shiftKey && !e.ctrlKey && !e.metaKey) {
      e.preventDefault();
      
      // Only add if current task is not empty
      if (tasks[index].title.trim() !== '' && tasks.length < 20) {
        addTask(index);
      }
    }
    
    // Ctrl/Cmd + Enter = Submit form
    if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) {
      e.preventDefault();
      handleSubmit(e);
    }
  };

  const handlePaste = (e, index) => {
    const pastedText = e.clipboardData.getData('text');
    
    // Check if pasted text contains multiple lines
    const lines = pastedText.split('\n').filter(line => line.trim() !== '');
    
    if (lines.length > 1) {
      e.preventDefault();
      
      // Calculate how many tasks we can add (max 20 total)
      const availableSlots = 20 - tasks.length + 1; // +1 because we replace current
      const tasksToAdd = lines.slice(0, availableSlots);
      
      // Create new tasks array
      const newTasks = [...tasks];
      
      // Replace current task with first line
      newTasks[index].title = tasksToAdd[0];
      
      // Add remaining lines as new tasks
      for (let i = 1; i < tasksToAdd.length; i++) {
        newTasks.splice(index + i, 0, { title: tasksToAdd[i] });
      }
      
      setTasks(newTasks);
      
      // Focus on the last added input after paste
      const lastAddedIndex = index + tasksToAdd.length - 1;
      setTimeout(() => {
        if (inputRefs.current[lastAddedIndex]) {
          inputRefs.current[lastAddedIndex].focus();
          // Move cursor to end of input
          const input = inputRefs.current[lastAddedIndex];
          if (input && input.setSelectionRange) {
            const length = input.value.length;
            input.setSelectionRange(length, length);
          }
        }
      }, 50);
      
      // Show info if some tasks were skipped
      if (lines.length > availableSlots) {
        setTimeout(() => {
          alert(`${availableSlots} tugas ditambahkan. ${lines.length - availableSlots} tugas dilewati (maksimal 20 tugas).`);
        }, 100);
      }
    }
  };

  const handleSubmit = (e) => {
    e.preventDefault();
    const validTasks = tasks.filter(t => t.title.trim() !== '');
    if (validTasks.length > 0) {
      onSubmit(validTasks);
      setTasks([{ title: '' }]);
    }
  };

  const handleClose = () => {
    setTasks([{ title: '' }]);
    onClose();
  };

  return (
    <Modal isOpen={isOpen} onClose={handleClose} title="Check In" size="lg">
      <form onSubmit={handleSubmit}>
        <div className="mb-4">
          <div className="flex items-start justify-between mb-3">
            <div>
              <p className="font-serif text-sm text-body">
                Tambahkan tugas yang akan Anda kerjakan hari ini
              </p>
            </div>
          </div>

          {/* Incomplete Tasks Section */}
          {incompleteTasks.length > 0 && (
            <div className="mb-4 p-4 bg-warning/10 border-2 border-warning rounded-card shadow-brutal-sm">
              <div className="flex items-start justify-between mb-2">
                <div className="flex items-center space-x-2">
                  <AlertCircle size={18} className="text-warning flex-shrink-0" />
                  <div>
                    <h4 className="text-title-sm text-warning">
                      Tugas Belum Selesai dari Sesi Terakhir ({incompleteTasks.length})
                    </h4>
                    <p className="font-serif text-xs text-muted mt-0.5">
                      Tugas yang belum diselesaikan dari sesi checkout terakhir Anda
                    </p>
                  </div>
                </div>
                <button
                  type="button"
                  onClick={() => setShowIncompleteTasks(!showIncompleteTasks)}
                  className="flex-shrink-0 font-mono text-caption uppercase text-muted hover:text-ink underline underline-offset-4 transition-colors"
                >
                  {showIncompleteTasks ? 'Sembunyikan' : 'Tampilkan'}
                </button>
              </div>

              {showIncompleteTasks && (
                <>
                  <div className="mt-3 space-y-2 max-h-48 overflow-y-auto">
                    {incompleteTasks.map((incTask, idx) => (
                      <div 
                        key={idx} 
                        className="flex items-start justify-between p-3 bg-white rounded-xl border-2 border-ink hover:shadow-brutal-sm transition-all"
                      >
                        <div className="flex-1 min-w-0">
                          <p className="font-serif text-sm text-body-strong truncate">
                            {incTask.title}
                          </p>
                          {incTask.blocker_reason && (
                            <p className="font-serif text-xs text-muted mt-1 line-clamp-2">
                              <span className="caption-uppercase">Blocker:</span> {incTask.blocker_reason}
                            </p>
                          )}
                        </div>
                        <button
                          type="button"
                          onClick={() => addIncompleteTask(incTask)}
                          className="ml-2 px-3 py-1.5 rounded-full border-2 border-ink bg-white font-display text-caption uppercase text-ink hover:bg-surface-elevated hover:shadow-brutal-sm transition-all flex-shrink-0"
                          title="Tambahkan tugas ini"
                        >
                          + Tambah
                        </button>
                      </div>
                    ))}
                  </div>
                  
                  <button
                    type="button"
                    onClick={addAllIncompleteTasks}
                    className="mt-3 w-full px-3 py-2 min-h-[38px] rounded-full border-2 border-ink bg-white font-display text-caption uppercase text-ink hover:bg-surface-elevated hover:shadow-brutal-sm transition-all flex items-center justify-center space-x-2"
                  >
                    <CheckSquare size={16} />
                    <span>Tambahkan Semua Tugas Belum Selesai</span>
                  </button>
                </>
              )}
            </div>
          )}

          {/* Paste Helper */}
          <div className="mb-3 p-3 bg-surface-soft border-2 border-ink rounded-xl">
            <div className="flex items-start space-x-2">
              <ClipboardPaste size={16} className="text-muted mt-0.5 flex-shrink-0" />
              <div className="font-serif text-xs text-body">
                <strong className="font-normal text-body-strong">Salin-Tempel Banyak Tugas:</strong> Tempel dari notepad/excel dengan setiap tugas di baris baru.
                Tugas akan otomatis dipisah menjadi tugas terpisah!
              </div>
            </div>
          </div>

          <div className="space-y-3 max-h-96 overflow-y-auto">
            {tasks.map((task, index) => (
              <div key={index} className="flex items-start space-x-2">
                <div className="flex-1">
                  <div ref={(el) => {
                    // Store the wrapper element and find input within it after render
                    if (el) {
                      setTimeout(() => {
                        const inputElement = el.querySelector('input');
                        if (inputElement) {
                          inputRefs.current[index] = inputElement;
                        }
                      }, 0);
                    }
                  }}>
                    <Input
                      value={task.title}
                      onChange={(e) => updateTask(index, e.target.value)}
                      onKeyDown={(e) => handleKeyDown(e, index)}
                      onPaste={(e) => handlePaste(e, index)}
                      placeholder={`Tugas ${index + 1}`}
                      required={index === 0}
                    />
                  </div>
                </div>
                {tasks.length > 1 && (
                  <button
                    type="button"
                    onClick={() => removeTask(index)}
                    className="p-2 text-muted hover:text-error transition-colors mt-1"
                    title="Hapus tugas"
                  >
                    <X size={20} />
                  </button>
                )}
              </div>
            ))}
          </div>

          {tasks.length < 20 && (
            <button
              type="button"
              onClick={addTask}
              className="mt-3 flex items-center space-x-2 font-mono text-nav-link uppercase text-muted hover:text-ink transition-colors"
            >
              <Plus size={20} />
              <span>Tambah Tugas ({tasks.length}/20)</span>
            </button>
          )}

          {tasks.length >= 20 && (
            <p className="mt-3 font-serif text-sm text-warning">
              Maksimal 20 tugas tercapai. Hapus beberapa tugas untuk menambah lebih banyak.
            </p>
          )}

          <label className="mt-4 flex cursor-pointer items-center gap-2">
            <input
              type="checkbox"
              checked={isStandby}
              onChange={(e) => handleStandbyChange(e.target.checked)}
              className="h-4 w-4 rounded accent-primary"
            />
            <span className="font-serif text-sm text-body">Standby (ceklist ini jika Anda dalam posisi standby / tidak ada task)</span>
          </label>
        </div>

        <div className="flex justify-end space-x-3">
          <Button type="button" variant="secondary" onClick={handleClose}>
            Batal
          </Button>
          <Button type="submit" disabled={loading || tasks.every(t => t.title.trim() === '')}>
            {loading ? 'Check In...' : 'Check In'}
          </Button>
        </div>
      </form>
    </Modal>
  );
};
