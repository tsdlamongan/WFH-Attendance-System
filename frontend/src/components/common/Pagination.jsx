import { ChevronLeft, ChevronRight } from 'lucide-react';

export const Pagination = ({ 
  currentPage, 
  lastPage, 
  perPage, 
  total, 
  from, 
  to, 
  onPageChange, 
  onPerPageChange 
}) => {
  const perPageOptions = [10, 50, 100, 1000];

  const handlePrevious = () => {
    if (currentPage > 1) {
      onPageChange(currentPage - 1);
    }
  };

  const handleNext = () => {
    if (currentPage < lastPage) {
      onPageChange(currentPage + 1);
    }
  };

  const handlePageClick = (page) => {
    onPageChange(page);
  };

  const getPageNumbers = () => {
    const pages = [];
    const maxPagesToShow = 5;
    
    if (lastPage <= maxPagesToShow) {
      // Show all pages if total pages is less than or equal to maxPagesToShow
      for (let i = 1; i <= lastPage; i++) {
        pages.push(i);
      }
    } else {
      // Show first page
      pages.push(1);
      
      // Calculate range around current page
      let start = Math.max(2, currentPage - 1);
      let end = Math.min(lastPage - 1, currentPage + 1);
      
      // Adjust if we're near the start
      if (currentPage <= 3) {
        end = 4;
      }
      
      // Adjust if we're near the end
      if (currentPage >= lastPage - 2) {
        start = lastPage - 3;
      }
      
      // Add ellipsis after first page if needed
      if (start > 2) {
        pages.push('...');
      }
      
      // Add middle pages
      for (let i = start; i <= end; i++) {
        pages.push(i);
      }
      
      // Add ellipsis before last page if needed
      if (end < lastPage - 1) {
        pages.push('...');
      }
      
      // Show last page
      pages.push(lastPage);
    }
    
    return pages;
  };

  return (
    <div className="flex flex-col sm:flex-row items-center justify-between gap-4 mt-8">
      {/* Info */}
      <div className="caption-uppercase">
        Menampilkan <span className="text-ink">{from || 0}</span> sampai{' '}
        <span className="text-ink">{to || 0}</span> dari{' '}
        <span className="text-ink">{total || 0}</span> data
      </div>

      {/* Per Page Selector */}
      <div className="flex items-center gap-3">
        <label className="caption-uppercase">Data per halaman:</label>
        <select
          value={perPage}
          onChange={(e) => onPerPageChange(Number(e.target.value))}
          className="rounded-input border-2 border-ink bg-white px-3 py-1.5 font-display text-sm font-semibold text-ink focus:border-brand focus:outline-none focus:shadow-focus-brand"
        >
          {perPageOptions.map((option) => (
            <option key={option} value={option}>
              {option}
            </option>
          ))}
        </select>
      </div>

      {/* Page Navigation */}
      <div className="flex items-center gap-2">
        <button
          onClick={handlePrevious}
          disabled={currentPage === 1}
          className={`flex items-center justify-center w-10 h-10 rounded-full border-2 transition-all ${
            currentPage === 1
              ? 'border-hairline text-muted-soft cursor-not-allowed'
              : 'border-ink text-ink hover:bg-surface-elevated hover:shadow-brutal-sm'
          }`}
        >
          <ChevronLeft size={18} />
        </button>

        {getPageNumbers().map((page, index) => (
          page === '...' ? (
            <span key={`ellipsis-${index}`} className="px-1 font-display text-sm font-bold text-muted">
              ...
            </span>
          ) : (
            <button
              key={page}
              onClick={() => handlePageClick(page)}
              className={`flex items-center justify-center w-10 h-10 rounded-full border-2 font-display text-sm font-bold transition-all ${
                currentPage === page
                  ? 'border-ink bg-primary text-white shadow-brutal-sm'
                  : 'border-ink text-ink hover:bg-surface-elevated hover:shadow-brutal-sm'
              }`}
            >
              {page}
            </button>
          )
        ))}

        <button
          onClick={handleNext}
          disabled={currentPage === lastPage}
          className={`flex items-center justify-center w-10 h-10 rounded-full border-2 transition-all ${
            currentPage === lastPage
              ? 'border-hairline text-muted-soft cursor-not-allowed'
              : 'border-ink text-ink hover:bg-surface-elevated hover:shadow-brutal-sm'
          }`}
        >
          <ChevronRight size={18} />
        </button>
      </div>
    </div>
  );
};
