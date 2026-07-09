import { X } from 'lucide-react';

export const Modal = ({ isOpen, onClose, title, children, size = 'md' }) => {
  if (!isOpen) return null;

  const sizeClasses = {
    sm: 'max-w-md',
    md: 'max-w-2xl',
    lg: 'max-w-4xl',
    xl: 'max-w-6xl',
  };

  return (
    <div className="fixed inset-0 z-50 overflow-y-auto">
      <div className="flex min-h-screen items-center justify-center p-4">
        {/* Backdrop */}
        <div
          className="fixed inset-0 bg-ink/50 transition-opacity"
          onClick={onClose}
        ></div>

        {/* Modal */}
        <div className={`relative bg-white border-2 border-ink rounded-card shadow-brutal-xl w-full ${sizeClasses[size]}`}>
          {/* Header */}
          <div className="flex items-center justify-between p-4 sm:p-6 border-b-2 border-ink">
            <h3 className="text-display-sm">{title}</h3>
            <button
              onClick={onClose}
              className="flex items-center justify-center w-10 h-10 rounded-full border-2 border-ink text-ink hover:bg-surface-elevated hover:shadow-brutal-sm transition-all"
            >
              <X size={18} />
            </button>
          </div>

          {/* Content */}
          <div className="p-4 sm:p-6">{children}</div>
        </div>
      </div>
    </div>
  );
};
