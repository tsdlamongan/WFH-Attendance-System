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
    <div className="mb-4">
      {label && (
        <label htmlFor={inputId} className="block text-sm font-medium text-gray-700 mb-2">
          {label}
          {required && <span className="text-red-500 ml-1">*</span>}
        </label>
      )}
      <input
        ref={ref}
        id={inputId}
        type={type}
        className={`input-field ${error ? 'border-red-500 focus:ring-red-500' : ''}`}
        {...props}
      />
      {error && <p className="mt-1 text-sm text-red-600">{error}</p>}
    </div>
  );
});
