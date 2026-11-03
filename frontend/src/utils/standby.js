/**
 * Standby detection on task titles. Must match the backend (backend/app/Models/Task.php):
 * - STANDBY_TASK_TITLE is the title the check-in checkbox inserts (Task::STANDBY_TITLE);
 * - STANDBY_PATTERN decides whether a title puts the session in standby mode (Task::STANDBY_PATTERN).
 *
 * The pattern matches "standby", "stand by", "stand-by", "standbye", "stanby" and the like
 * anywhere in the title, but not words such as "standard" or "standing".
 */
export const STANDBY_TASK_TITLE = 'Standby';

export const STANDBY_PATTERN = /\bstand?[\s_-]*(?:by|bye|bay)\b/i;

export const isStandbyTitle = (title) => STANDBY_PATTERN.test(String(title ?? ''));
