import { useId } from 'react';
import {
  OVERTIME_MIN_MINUTES,
  OVERTIME_MAX_MINUTES,
  OVERTIME_STEP_MINUTES,
  OVERTIME_REASON_MIN_LENGTH,
  OVERTIME_REASON_MAX_LENGTH,
} from '../../utils/constants';
import { formatMinutes } from '../../utils/dateHelpers';

const DURATION_OPTIONS = Array.from(
  { length: Math.floor((OVERTIME_MAX_MINUTES - OVERTIME_MIN_MINUTES) / OVERTIME_STEP_MINUTES) + 1 },
  (_, i) => OVERTIME_MIN_MINUTES + i * OVERTIME_STEP_MINUTES
);

/**
 * "Alasan lembur" textarea shared by every form that creates an overtime record
 * (check-in modal, employee request modal, manager assign modal).
 * Spacing is owned by the parent (use gap-*), so no margins are baked in.
 */
export const OvertimeReasonField = ({
  value,
  onChange,
  label = 'Alasan lembur',
  placeholder = `Jelaskan alasan lembur (minimal ${OVERTIME_REASON_MIN_LENGTH} karakter)`,
  rows = 3,
  id,
}) => {
  const autoId = useId();
  const fieldId = id || autoId;

  return (
    <div>
      <label htmlFor={fieldId} className="caption-uppercase block mb-2">
        {label} <span className="text-error">*</span>
      </label>
      <textarea
        id={fieldId}
        value={value}
        onChange={(e) => onChange(e.target.value)}
        className="input-field"
        rows={rows}
        placeholder={placeholder}
        required
        minLength={OVERTIME_REASON_MIN_LENGTH}
        maxLength={OVERTIME_REASON_MAX_LENGTH}
      />
      <p className="caption-uppercase mt-2">
        {value.length}/{OVERTIME_REASON_MAX_LENGTH} karakter (minimal {OVERTIME_REASON_MIN_LENGTH})
      </p>
    </div>
  );
};

/** Duration select in 15-minute steps (15..480 minutes). `value` / `onChange` use a number of minutes. */
export const OvertimeDurationSelect = ({ value, onChange, label = 'Durasi lembur', id }) => {
  const autoId = useId();
  const fieldId = id || autoId;

  return (
    <div>
      <label htmlFor={fieldId} className="caption-uppercase block mb-2">
        {label} <span className="text-error">*</span>
      </label>
      <select
        id={fieldId}
        value={value}
        onChange={(e) => onChange(Number(e.target.value))}
        className="input-field"
      >
        {DURATION_OPTIONS.map((minutes) => (
          <option key={minutes} value={minutes}>
            {formatMinutes(minutes)}
          </option>
        ))}
      </select>
    </div>
  );
};
