export const APP_NAME = 'WFH - Work From Home Attendance System';

export const USER_ROLES = {
  EMPLOYEE: 'employee',
  MANAGER: 'manager',
};

export const LEAVE_STATUS = {
  PENDING: 'pending',
  APPROVED: 'approved',
  REJECTED: 'rejected',
};

export const OVERTIME_STATUS = {
  PENDING: 'pending',
  APPROVED: 'approved',
  REJECTED: 'rejected',
};

export const OVERTIME_STATUS_LABELS = {
  [OVERTIME_STATUS.PENDING]: 'Menunggu',
  [OVERTIME_STATUS.APPROVED]: 'Disetujui',
  [OVERTIME_STATUS.REJECTED]: 'Ditolak',
};

export const OVERTIME_STATUS_BADGES = {
  [OVERTIME_STATUS.PENDING]: 'badge-warning',
  [OVERTIME_STATUS.APPROVED]: 'badge-success',
  [OVERTIME_STATUS.REJECTED]: 'badge-danger',
};

// Must match the backend validation: planned_minutes / approved_minutes are integers 15..480.
export const OVERTIME_MIN_MINUTES = 15;
export const OVERTIME_MAX_MINUTES = 480;
export const OVERTIME_STEP_MINUTES = 15;
export const OVERTIME_DEFAULT_MINUTES = 60;
// Backend: reason is a string of 5..500 characters.
export const OVERTIME_REASON_MIN_LENGTH = 5;
export const OVERTIME_REASON_MAX_LENGTH = 500;

export const ATTENDANCE_STATUS = {
  CHECKED_IN: 'checked_in',
  CHECKED_OUT: 'checked_out',
  ON_LEAVE: 'on_leave',
};

export const ACTIVITY_TYPES = {
  CHECK_IN: 'check_in',
  CHECK_OUT: 'check_out',
  TASK_CREATED: 'task_created',
  TASK_UPDATED: 'task_updated',
  ATTENDANCE_EDITED: 'attendance_edited',
  ATTENDANCE_DELETED: 'attendance_deleted',
  USER_CREATED: 'user_created',
  USER_UPDATED: 'user_updated',
  USER_DELETED: 'user_deleted',
  LEAVE_REQUESTED: 'leave_requested',
  LEAVE_APPROVED: 'leave_approved',
  LEAVE_REJECTED: 'leave_rejected',
  HOLIDAY_CREATED: 'holiday_created',
  HOLIDAY_UPDATED: 'holiday_updated',
  HOLIDAY_DELETED: 'holiday_deleted',
  AUTO_CHECKOUT: 'auto_checkout',
  LOGIN: 'login',
  LOGOUT: 'logout',
};

export const REQUIRED_WORK_HOURS = 7;
