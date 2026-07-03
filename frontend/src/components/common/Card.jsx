export const Card = ({ children, className = '', title, action }) => {
  return (
    <div className={`card ${className}`}>
      {(title || action) && (
        <div className="flex items-center justify-between gap-4 mb-6">
          {title && <h3 className="text-display-sm">{title}</h3>}
          {action && <div>{action}</div>}
        </div>
      )}
      {children}
    </div>
  );
};
