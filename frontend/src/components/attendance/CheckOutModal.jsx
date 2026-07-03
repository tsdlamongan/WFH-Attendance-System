import { useState, useEffect } from 'react';
import { Modal } from '../common/Modal';
import { Button } from '../common/Button';
import { Input } from '../common/Input';
import toast from 'react-hot-toast';

export const CheckOutModal = ({ isOpen, onClose, onSubmit, loading, tasks = [], attendanceId }) => {
  const [taskStatuses, setTaskStatuses] = useState([]);

  // Initialize or update taskStatuses when tasks change
  useEffect(() => {
    if (tasks && tasks.length > 0) {
      setTaskStatuses(
        tasks.map(task => ({
          id: task.id,
          is_completed: false,
          blocker_reason: '',
        }))
      );
    }
  }, [tasks]);

  const updateTaskStatus = (index, field, value) => {
    const newStatuses = [...taskStatuses];
    newStatuses[index][field] = value;
    
    // Clear blocker reason if task is completed
    if (field === 'is_completed' && value === true) {
      newStatuses[index].blocker_reason = '';
    }
    
    setTaskStatuses(newStatuses);
  };

  const handleSubmit = (e) => {
    e.preventDefault();
    
    // Validate: incomplete tasks must have blocker reason
    const hasError = taskStatuses.some(
      status => !status.is_completed && !status.blocker_reason.trim()
    );
    
    if (hasError) {
      toast.error('Alasan kendala wajib diisi untuk tugas yang belum selesai');
      return;
    }
    
    onSubmit(attendanceId, taskStatuses);
  };

  // Handle case where tasks are not loaded properly
  if (!tasks || tasks.length === 0) {
    return (
      <Modal isOpen={isOpen} onClose={onClose} title="Check Out" size="lg">
        <div className="text-center py-8">
          <p className="font-serif text-body mb-4">?? Tidak ada tugas ditemukan untuk sesi ini.</p>
          <p className="font-serif text-sm text-muted mb-6">
            Ini mungkin terjadi karena masalah loading data. Anda masih bisa checkout tanpa update status tugas.
          </p>
          <div className="flex justify-center space-x-3">
            <Button type="button" variant="secondary" onClick={onClose}>
              Batal
            </Button>
            <Button 
              type="button" 
              onClick={() => {
                // Force checkout with empty tasks array
                console.warn('Forcing checkout without tasks');
                onSubmit(attendanceId, []);
              }}
              disabled={loading || !attendanceId}
            >
              {loading ? 'Check Out...' : 'Paksa Check Out'}
            </Button>
          </div>
        </div>
      </Modal>
    );
  }

  return (
    <Modal isOpen={isOpen} onClose={onClose} title="Check Out" size="lg">
      <form onSubmit={handleSubmit}>
        <div className="mb-4">
          <p className="font-serif text-sm text-body mb-4">
            Tandai status penyelesaian tugas Anda dan berikan alasan untuk tugas yang belum selesai
          </p>

          <div className="space-y-4">
            {tasks.map((task, index) => (
              <div key={task.id} className="border border-hairline rounded-none p-4">
                <div className="flex items-start space-x-3 mb-3">
                  <input
                    type="checkbox"
                    checked={taskStatuses[index]?.is_completed || false}
                    onChange={(e) => updateTaskStatus(index, 'is_completed', e.target.checked)}
                    className="mt-1 h-5 w-5 rounded-none accent-ink"
                  />
                  <div className="flex-1">
                    <p className="font-serif text-body-strong">{task.title}</p>
                  </div>
                </div>

                {taskStatuses[index] && !taskStatuses[index].is_completed && (
                  <div className="ml-8">
                    <Input
                      label="Alasan Blocker"
                      value={taskStatuses[index].blocker_reason}
                      onChange={(e) => updateTaskStatus(index, 'blocker_reason', e.target.value)}
                      placeholder="Mengapa Anda tidak bisa menyelesaikan tugas ini?"
                      required={!taskStatuses[index].is_completed}
                    />
                  </div>
                )}
              </div>
            ))}
          </div>
        </div>

        <div className="flex justify-end space-x-3">
          <Button type="button" variant="secondary" onClick={onClose}>
            Batal
          </Button>
          <Button type="submit" disabled={loading || taskStatuses.length === 0}>
            {loading ? 'Check Out...' : 'Check Out'}
          </Button>
        </div>
      </form>
    </Modal>
  );
};
;
