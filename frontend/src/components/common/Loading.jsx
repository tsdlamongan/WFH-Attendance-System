export const Loading = ({ fullScreen = false }) => {
  if (fullScreen) {
    return (
      <div className="min-h-screen flex items-center justify-center bg-canvas">
        <div className="flex flex-col items-center">
          <div className="animate-spin rounded-full h-11 w-11 border-[3px] border-hairline border-t-primary"></div>
          <p className="mt-5 caption-uppercase">Loading...</p>
        </div>
      </div>
    );
  }

  return (
    <div className="flex items-center justify-center py-8">
      <div className="animate-spin rounded-full h-8 w-8 border-[3px] border-hairline border-t-primary"></div>
    </div>
  );
};
