import { forwardRef, useId } from 'react';

export const Input = forwardRef(({
  label,
  error,
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
        className={`input-field ${error ? 'border-error focus:border-error' : ''}`}
        {...props}
      />
      {error && <p className="mt-2 font-serif text-sm text-error">{error}</p>}
    </div>
  );
});
