// Helper to mock the tasks an auto checkout left open, at the frontend HTTP layer.
//
// An auto-checked-out session needs a full workday plus the cron, so the dashboard card is driven
// by a mocked /attendance/today (same approach as overtime-mock.js). Saving is mocked too: the
// POST is recorded for payload assertions, and the saved tasks drop out of the next status poll,
// as they do on the backend. The backend rules themselves are covered by AutoCheckoutTaskCompletionTest.

const json = (body, status = 200) => ({
  status,
  contentType: 'application/json',
  body: JSON.stringify(body),
});

/** yyyy-MM-dd of today shifted by `offsetDays`, in the browser's (= this machine's) timezone. */
export function ymd(offsetDays = 0) {
  const date = new Date();
  date.setDate(date.getDate() + offsetDays);
  const pad = (value) => String(value).padStart(2, '0');
  return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`;
}

export async function mockAutoCheckoutTasks(page, opts = {}) {
  const {
    // [{ id, title, project, date }] — see AttendanceService::getTodayStatus() auto_checkout_pending_tasks
    tasks = [],
    // When set, the POST answers 422 with this message (e.g. a task fell out of reach).
    completeError = null,
  } = opts;

  const state = {
    tasks: tasks.map((task) => ({ project_id: null, project: null, blocker_reason: 'belum selesai', ...task })),
    completeError,
    completeRequests: [],
  };

  await page.route('**/api/v1/attendance/today', async (route) => {
    await route.fulfill(
      json({
        success: true,
        data: {
          date: ymd(),
          is_checked_in: false,
          current_session: null,
          today_total_hours: 7,
          required_hours: 7,
          remaining_hours: 0,
          previous_sessions: [],
          last_auto_checkout: null,
          requires_overtime_request: false,
          overtime: null,
          auto_checkout_pending_tasks: state.tasks,
        },
      })
    );
  });

  await page.route('**/api/v1/tasks/complete-after-auto-checkout', async (route) => {
    if (route.request().method() !== 'POST') {
      await route.fallback();
      return;
    }
    const payload = route.request().postDataJSON();
    state.completeRequests.push(payload);

    if (state.completeError) {
      await route.fulfill(json({ success: false, message: state.completeError }, 422));
      return;
    }

    state.tasks = state.tasks.filter((task) => !payload.task_ids.includes(task.id));
    await route.fulfill(
      json({ success: true, message: `${payload.task_ids.length} tugas ditandai selesai`, data: { tasks: [] } })
    );
  });

  return state;
}
