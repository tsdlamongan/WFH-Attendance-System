import { useState } from 'react';
import { Card } from '../common/Card';
import { Button } from '../common/Button';
import { ProjectTag } from '../common/ProjectTag';
import { formatDate, getTodayDate, getYesterdayDate } from '../../utils/dateHelpers';
import { CheckCircle } from 'lucide-react';

const dayLabel = (date) => {
  if (date === getTodayDate()) return `Hari ini · ${formatDate(date)}`;
  if (date === getYesterdayDate()) return `Kemarin · ${formatDate(date)}`;
  return formatDate(date);
};

// Tasks in the order they arrive (oldest session first), grouped per day.
const groupByDate = (tasks) =>
  tasks.reduce((groups, task) => {
    const group = groups.find((item) => item.date === task.date);
    if (group) {
      group.tasks.push(task);
    } else {
      groups.push({ date: task.date, tasks: [task] });
    }
    return groups;
  }, []);

/**
 * Tasks an auto checkout left open. The employee could not update them at checkout, so they can
 * tick off the ones that were in fact finished (sessions of today and yesterday).
 */
export const AutoCheckoutTasksCard = ({ tasks, onSubmit, loading }) => {
  const [selectedIds, setSelectedIds] = useState([]);

  // A task that is gone after a refresh (saved, or out of reach) cannot stay selected.
  const selected = selectedIds.filter((id) => tasks.some((task) => task.id === id));

  const toggle = (id) =>
    setSelectedIds(selected.includes(id) ? selected.filter((item) => item !== id) : [...selected, id]);

  const handleSubmit = async (e) => {
    e.preventDefault();
    if (selected.length === 0) return;

    const saved = await onSubmit(selected);
    if (saved) setSelectedIds([]);
  };

  return (
    <Card title="Tugas Terkena Auto Checkout">
      <form onSubmit={handleSubmit} data-testid="auto-checkout-tasks">
        <p className="font-serif text-sm text-body mb-4">
          Sesi Anda ditutup otomatis, jadi tugas di bawah belum sempat diperbarui. Centang tugas yang
          sebenarnya sudah selesai, lalu simpan. Hanya bisa untuk sesi hari ini dan kemarin, dan tugas yang
          sudah ditandai selesai tidak bisa dibatalkan.
        </p>

        <div className="space-y-5">
          {groupByDate(tasks).map((group) => (
            <div key={group.date}>
              <p className="caption-uppercase mb-2">{dayLabel(group.date)}</p>
              <ul className="space-y-2">
                {group.tasks.map((task) => (
                  <li key={task.id}>
                    <label className="flex cursor-pointer items-start gap-3 rounded-xl border-2 border-ink bg-surface-soft p-3">
                      <input
                        type="checkbox"
                        checked={selected.includes(task.id)}
                        onChange={() => toggle(task.id)}
                        className="mt-1 h-5 w-5 shrink-0 rounded-sm accent-primary"
                      />
                      <span className="flex flex-wrap items-center gap-2">
                        <span className="font-serif text-body-strong">{task.title}</span>
                        <ProjectTag name={task.project} />
                      </span>
                    </label>
                  </li>
                ))}
              </ul>
            </div>
          ))}
        </div>

        <div className="mt-5 flex justify-end">
          <Button type="submit" disabled={loading || selected.length === 0}>
            <CheckCircle size={20} />
            <span>{loading ? 'Menyimpan...' : `Tandai Selesai${selected.length > 0 ? ` (${selected.length})` : ''}`}</span>
          </Button>
        </div>
      </form>
    </Card>
  );
};
