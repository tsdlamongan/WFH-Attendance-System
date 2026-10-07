/** Small pill naming the project a task was logged for. Renders nothing for a task without project. */
export const ProjectTag = ({ name, className = '' }) => {
  if (!name) return null;

  return (
    <span className={`badge badge-muted shadow-none ${className}`} title="Project">
      {name}
    </span>
  );
};
