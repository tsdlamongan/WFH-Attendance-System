import { useEffect, useSyncExternalStore } from 'react';
import { getPendingOvertimeCount } from '../api/manager.api';

const POLL_INTERVAL_MS = 60_000;
// Every page mounts its own MainLayout/Sidebar, so the hook re-subscribes on each navigation.
// Skip the immediate fetch when the shared count is this fresh.
const MIN_FETCH_GAP_MS = 5_000;

// Module-level store shared by every subscriber (Sidebar + OvertimeApproval), so there is a single
// polling interval no matter how many components use the hook.
let count = 0;
let subscribers = 0;
let intervalId = null;
let lastFetchedAt = 0;
const listeners = new Set();

const subscribe = (listener) => {
  listeners.add(listener);
  return () => listeners.delete(listener);
};

const getSnapshot = () => count;

/**
 * Fetches the pending-overtime count and publishes it to every subscriber.
 * Fails silently: a missed poll just keeps the previous count.
 * Call it after approving/rejecting so the sidebar badge updates without waiting for the next poll.
 */
export const refreshPendingOvertimeCount = async () => {
  lastFetchedAt = Date.now();
  try {
    const response = await getPendingOvertimeCount();
    const next = Number(response?.data?.count);
    if (response?.success && Number.isFinite(next) && next !== count) {
      count = next;
      listeners.forEach((listener) => listener());
    }
  } catch {
    // Intentionally silent (lightweight background poll).
  }
};

/**
 * Pending overtime request count for the manager's team, polled every 60s.
 * Pass `enabled = false` for non-managers: nothing is fetched and 0 is returned.
 */
export const usePendingOvertimeCount = (enabled = true) => {
  const value = useSyncExternalStore(subscribe, getSnapshot);

  useEffect(() => {
    if (!enabled) return undefined;

    subscribers += 1;
    if (subscribers === 1) {
      if (Date.now() - lastFetchedAt > MIN_FETCH_GAP_MS) {
        refreshPendingOvertimeCount();
      }
      intervalId = setInterval(refreshPendingOvertimeCount, POLL_INTERVAL_MS);
    }

    return () => {
      subscribers -= 1;
      if (subscribers === 0) {
        clearInterval(intervalId);
        intervalId = null;
      }
    };
  }, [enabled]);

  return enabled ? value : 0;
};
