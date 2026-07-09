import { forwardRef, useId } from 'react';

export const Input = forwardRef(({
  label,
  error,
  helperText,
  type = 'text',
  required = false,
  id,
  ...props
}, ref) => {
  const autoId = useId();
  const inputId = id || autoId;
  return (
    <div className="mb-6">
      {label && (
        <label htmlFor={inputId} className="caption-uppercase block mb-2">
          {label}
          {required && <span className="text-error ml-1">*</span>}
        </label>
      )}
      <input
        ref={ref}
        id={inputId}
        type={type}
        className={`input-field ${error ? 'border-error bg-error/5 focus:border-error focus:shadow-none' : ''}`}
        {...props}
      />
      {error && <p className="mt-2 font-sans text-sm text-error">{error}</p>}
      {!error && helperText && <p className="mt-1.5 font-sans text-xs text-muted">{helperText}</p>}
    </div>
  );
});
