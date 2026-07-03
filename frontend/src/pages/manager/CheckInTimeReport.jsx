import { useState, useEffect } from 'react';
import { MainLayout } from '../../components/layout/MainLayout';
import { Card } from '../../components/common/Card';
import { Loading } from '../../components/common/Loading';
import { getCheckInTimeReport } from '../../api/manager.api';
import { formatDate, getMonthStart, getMonthEnd } from '../../utils/dateHelpers';
import { usePageTitle } from '../../hooks/usePageTitle';
import { Clock, TrendingUp, Calendar, Award } from 'lucide-react';
import toast from 'react-hot-toast';

export function CheckInTimeReport() {
  usePageTitle('Laporan Waktu Check-In');

  const [loading, setLoading] = useState(true);
  const [startDate, setStartDate] = useState(getMonthStart());
  const [endDate, setEndDate] = useState(getMonthEnd());
  const [report, setReport] = useState(null);
  const [expandedEmployees, setExpandedEmployees] = useState(new Set());

  useEffect(() => {
    fetchReport();
  }, [startDate, endDate]);

  const fetchReport = async () => {
    try {
      setLoading(true);
      const response = await getCheckInTimeReport(startDate, endDate);
      if (response.success) {
        setReport(response.data);
      }
    } catch (error) {
      toast.error(error.response?.data?.message || 'Gagal mengambil data laporan');
      console.error('Failed to fetch check-in time report:', error);
    } finally {
      setLoading(false);
    }
  };

  const toggleEmployeeDetails = (employeeId) => {
    const newExpanded = new Set(expandedEmployees);
    if (newExpanded.has(employeeId)) {
      newExpanded.delete(employeeId);
    } else {
      newExpanded.add(employeeId);
    }
    setExpandedEmployees(newExpanded);
  };

  const getConsistencyBadge = (rate) => {
    if (rate >= 90) return { label: 'Sangat Baik', color: 'badge badge-success' };
    if (rate >= 75) return { label: 'Baik', color: 'badge badge-info' };
    if (rate >= 50) return { label: 'Cukup', color: 'badge badge-warning' };
    return { label: 'Perlu Perbaikan', color: 'badge badge-danger' };
  };

  if (loading) {
    return (
      <MainLayout>
        <Loading />
      </MainLayout>
    );
  }

  return (
    <MainLayout>
      <div className="space-y-6 sm:space-y-8">
      {/* Header */}
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-display-md sm:text-display-lg">Laporan Waktu Check-In</h1>
          <p className="mt-2 font-serif text-body">
            Analisis konsistensi waktu check-in karyawan
          </p>
        </div>
      </div>

      {/* Date Range Filter */}
      <Card>
        <div className="flex flex-wrap gap-4 items-end">
          <div className="flex-1 min-w-[200px]">
            <label className="caption-uppercase block mb-2">
              Tanggal Mulai
            </label>
            <input
              type="date"
              value={startDate}
              onChange={(e) => setStartDate(e.target.value)}
              className="input-field w-full"
            />
          </div>

          <div className="flex-1 min-w-[200px]">
            <label className="caption-uppercase block mb-2">
              Tanggal Akhir
            </label>
            <input
              type="date"
              value={endDate}
              onChange={(e) => setEndDate(e.target.value)}
              className="input-field w-full"
            />
          </div>

          <button
            onClick={fetchReport}
            className="btn-primary w-full sm:w-auto whitespace-nowrap"
          >
            <Calendar className="w-4 h-4" />
            Terapkan Filter
          </button>
        </div>

        {report && (
          <div className="mt-4 p-4 border border-hairline bg-surface-soft rounded-none">
            <div className="flex items-center font-serif text-sm text-body">
              <Clock className="w-5 h-5 mr-2 text-muted flex-shrink-0" />
              <span>
                Rentang waktu yang diukur: <strong className="font-normal font-mono text-ink">{report.window_start}</strong> - <strong className="font-normal font-mono text-ink">{report.window_end}</strong>
              </span>
            </div>
            <p className="font-serif text-xs text-muted mt-1">
              Rentang waktu ini dapat diubah di halaman Pengaturan Tim
            </p>
          </div>
        )}
      </Card>

      {/* Statistics Summary */}
      {report && report.employees.length > 0 && (
        <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 sm:gap-6">
          <Card>
            <div className="flex items-center">
              <div className="flex-shrink-0 w-10 h-10 rounded-full border border-hairline-strong flex items-center justify-center">
                <Award className="h-5 w-5 text-muted" />
              </div>
              <div className="ml-4">
                <p className="caption-uppercase">Total Karyawan</p>
                <p className="font-display text-display-md text-ink">{report.employees.length}</p>
              </div>
            </div>
          </Card>

          <Card>
            <div className="flex items-center">
              <div className="flex-shrink-0 w-10 h-10 rounded-full border border-hairline-strong flex items-center justify-center">
                <TrendingUp className="h-5 w-5 text-muted" />
              </div>
              <div className="ml-4">
                <p className="caption-uppercase">Konsistensi {'>'}= 90%</p>
                <p className="font-display text-display-md text-ink">
                  {report.employees.filter(e => e.consistency_rate >= 90).length}
                </p>
              </div>
            </div>
          </Card>

          <Card>
            <div className="flex items-center">
              <div className="flex-shrink-0 w-10 h-10 rounded-full border border-hairline-strong flex items-center justify-center">
                <Clock className="h-5 w-5 text-muted" />
              </div>
              <div className="ml-4">
                <p className="caption-uppercase">Rata-rata Konsistensi</p>
                <p className="font-display text-display-md text-ink">
                  {(report.employees.reduce((sum, e) => sum + e.consistency_rate, 0) / report.employees.length).toFixed(1)}%
                </p>
              </div>
            </div>
          </Card>

          <Card>
            <div className="flex items-center">
              <div className="flex-shrink-0 w-10 h-10 rounded-full border border-hairline-strong flex items-center justify-center">
                <Calendar className="h-5 w-5 text-muted" />
              </div>
              <div className="ml-4">
                <p className="caption-uppercase">Periode</p>
                <p className="font-mono text-sm text-ink">
                  {formatDate(report.start_date)} - {formatDate(report.end_date)}
                </p>
              </div>
            </div>
          </Card>
        </div>
      )}

      {/* Employee List */}
      {report && report.employees.length > 0 ? (
        <Card>
          <h2 className="text-display-sm mb-4">Daftar Karyawan</h2>
          <div className="space-y-4">
            {report.employees.map((employeeData) => {
              const isExpanded = expandedEmployees.has(employeeData.employee.id);
              const badge = getConsistencyBadge(employeeData.consistency_rate);

              return (
                <div
                  key={employeeData.employee.id}
                  className="border border-hairline rounded-none overflow-hidden"
                >
                  {/* Employee Summary */}
                  <div
                    onClick={() => toggleEmployeeDetails(employeeData.employee.id)}
                    className="p-4 bg-surface-soft hover:bg-surface-elevated cursor-pointer transition-colors"
                  >
                    <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                      <div className="flex-1">
                        <h3 className="text-title-md">
                          {employeeData.employee.name}
                        </h3>
                        <p className="font-serif text-sm text-muted">{employeeData.employee.email}</p>
                      </div>

                      <div className="flex flex-wrap items-center gap-4">
                        <div className="text-left sm:text-right">
                          <p className="caption-uppercase">Tepat Waktu</p>
                          <p className="font-mono text-lg text-success">
                            {employeeData.on_time_days} / {employeeData.total_days} hari
                          </p>
                        </div>

                        <div className="text-left sm:text-right">
                          <p className="caption-uppercase">Konsistensi</p>
                          <div className="flex items-center gap-2">
                            <p className="font-mono text-lg text-ink">
                              {employeeData.consistency_rate}%
                            </p>
                            <span className={badge.color}>
                              {badge.label}
                            </span>
                          </div>
                        </div>

                        <button className="text-muted hover:text-ink transition-colors">
                          {isExpanded ? '▲' : '▼'}
                        </button>
                      </div>
                    </div>
                  </div>

                  {/* Employee Details */}
                  {isExpanded && (
                    <div className="p-4 border-t border-hairline">
                      <h4 className="text-title-sm mb-3">Detail Harian</h4>
                      <div className="overflow-x-auto">
                        <table className="w-full">
                          <thead>
                            <tr className="border-b border-hairline">
                              <th className="caption-uppercase text-left px-4 py-3">
                                Tanggal
                              </th>
                              <th className="caption-uppercase text-left px-4 py-3">
                                Jam Check-In
                              </th>
                              <th className="caption-uppercase text-left px-4 py-3">
                                Status
                              </th>
                            </tr>
                          </thead>
                          <tbody>
                            {employeeData.details.map((detail, index) => (
                              <tr key={index} className="border-b border-hairline last:border-0 hover:bg-surface-soft transition-colors">
                                <td className="px-4 py-3 font-mono text-sm text-muted">
                                  {formatDate(detail.date)}
                                </td>
                                <td className="px-4 py-3 font-mono text-sm text-ink">
                                  {detail.check_in_time}
                                </td>
                                <td className="px-4 py-3">
                                  <span
                                    className={`badge ${
                                      detail.is_on_time
                                        ? 'badge-success'
                                        : 'badge-danger'
                                    }`}
                                  >
                                    {detail.is_on_time ? 'Tepat Waktu' : 'Terlambat'}
                                  </span>
                                </td>
                              </tr>
                            ))}
                          </tbody>
                        </table>
                      </div>
                    </div>
                  )}
                </div>
              );
            })}
          </div>
        </Card>
      ) : (
        !loading && (
          <Card>
            <div className="text-center py-16">
              <Clock className="mx-auto h-12 w-12 text-muted" />
              <h3 className="mt-4 text-title-sm">Tidak ada data</h3>
              <p className="mt-2 font-serif text-sm text-muted">
                Tidak ada data absensi untuk rentang tanggal yang dipilih.
              </p>
            </div>
          </Card>
        )
      )}
      </div>
    </MainLayout>
  );
}
