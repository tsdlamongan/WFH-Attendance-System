import { useId } from 'react';
import { Input } from '../common/Input';
import { EMPLOYMENT_TYPE_LABELS } from '../../utils/constants';

/**
 * "Jabatan" and "Status karyawan" inputs shared by the manager and super admin user forms.
 * Both values are printed on the evaluation report. `onChange` receives the changed form keys,
 * e.g. `{ job_title: 'QA' }`.
 */
export const EmploymentFields = ({ jobTitle, employmentType, onChange }) => {
  const selectId = useId();

  return (
    <>
      <Input
        label="Jabatan"
        value={jobTitle}
        onChange={(e) => onChange({ job_title: e.target.value })}
        placeholder="contoh: Fullstack Developer"
        maxLength={100}
        helperText="Opsional. Tampil di kolom Jabatan laporan evaluasi."
      />

      <div className="mb-6">
        <label htmlFor={selectId} className="caption-uppercase block mb-2">
          Status Karyawan
        </label>
        <select
          id={selectId}
          value={employmentType}
          onChange={(e) => onChange({ employment_type: e.target.value })}
          className="input-field"
        >
          {Object.entries(EMPLOYMENT_TYPE_LABELS).map(([value, label]) => (
            <option key={value} value={value}>{label}</option>
          ))}
        </select>
      </div>
    </>
  );
};
