// Helper to mock today's attendance status + check-in at the frontend HTTP layer.
//
// Reaching "required hours already met" for real needs a full workday of attendance, so the
// overtime-aware check-in modal and dashboard banner are driven by a mocked /attendance/today
// (same approach as whatsapp-mock.js). The check-in POST is recorded instead of hitting the
// backend, so no attendance row is created and specs can assert on the exact payload.

const json = (body, status = 200) => ({
  status,
  contentType: 'application/json',
  body: JSON.stringify(body),
});

export async function mockTodayOvertimeState(page, opts = {}) {
  const {
    requiresOvertime = false,
    // null | { status, planned_minutes, approved_minutes, allowed_minutes, used_minutes,
    //          remaining_minutes, reason, notes } — see AttendanceService::getTodayStatus()
    overtime = null,
    // When set, POST /attendance/check-in answers 422 with this message (e.g. rejected overtime).
    checkInError = null,
  } = opts;

  const state = { requiresOvertime, overtime, checkInError, checkInRequests: [] };

  await page.route('**/api/v1/attendance/today', async (route) => {
    await route.fulfill(
      json({
        success: true,
        data: {
          date: new Date().toISOString().split('T')[0],
          is_checked_in: false,
          current_session: null,
          today_total_hours: 7,
          required_hours: 7,
          remaining_hours: 0,
          previous_sessions: [],
          last_auto_checkout: null,
          requires_overtime_request: state.requiresOvertime,
          overtime: state.overtime
            ? { id: 1, date: new Date().toISOString().split('T')[0], ...state.overtime }
            : null,
        },
      })
    );
  });

  await page.route('**/api/v1/attendance/check-in', async (route) => {
    if (route.request().method() !== 'POST') {
      await route.fallback();
      return;
    }
    state.checkInRequests.push(route.request().postDataJSON());
    if (state.checkInError) {
      await route.fulfill(json({ success: false, message: state.checkInError }, 422));
      return;
    }
    await route.fulfill(json({ success: true, message: 'Check-in berhasil', data: {} }, 201));
  });

  return state;
}
