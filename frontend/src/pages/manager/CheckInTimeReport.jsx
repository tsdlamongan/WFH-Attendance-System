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
    if (rate >= 90) return { label: 'Sangat Baik', color: 'bg-green-100 text-green-800' };
    if (rate >= 75) return { label: 'Baik', color: 'bg-blue-100 text-blue-800' };
    if (rate >= 50) return { label: 'Cukup', color: 'bg-yellow-100 text-yellow-800' };
    return { label: 'Perlu Perbaikan', color: 'bg-red-100 text-red-800' };
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
      <div className="space-y-6">
      {/* Header */}
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-3xl font-bold text-gray-900">Laporan Waktu Check-In</h1>
          <p className="mt-2 text-gray-600">
            Analisis konsistensi waktu check-in karyawan
          </p>
        </div>
      </div>

      {/* Date Range Filter */}
      <Card>
        <div className="flex flex-wrap gap-4 items-end">
          <div className="flex-1 min-w-[200px]">
            <label className="block text-sm font-medium text-gray-700 mb-2">
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
            <label className="block text-sm font-medium text-gray-700 mb-2">
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
            className="btn btn-primary px-6 whitespace-nowrap flex items-center"
          >
            <Calendar className="w-4 h-4 mr-2" />
            Terapkan Filter
          </button>
        </div>

        {report && (
          <div className="mt-4 p-4 bg-blue-50 rounded-lg">
            <div className="flex items-center text-sm text-blue-800">
              <Clock className="w-5 h-5 mr-2" />
              <span>
                Rentang waktu yang diukur: <strong>{report.window_start}</strong> - <strong>{report.window_end}</strong>
              </span>
            </div>
            <p className="text-xs text-blue-600 mt-1">
              Rentang waktu ini dapat diubah di halaman Pengaturan Tim
            </p>
          </div>
        )}
      </Card>

      {/* Statistics Summary */}
      {report && report.employees.length > 0 && (
        <div className="grid grid-cols-1 md:grid-cols-4 gap-6">
          <Card>
            <div className="flex items-center">
              <div className="flex-shrink-0">
                <Award className="h-8 w-8 text-blue-600" />
              </div>
              <div className="ml-4">
                <p className="text-sm font-medium text-gray-600">Total Karyawan</p>
                <p className="text-2xl font-bold text-gray-900">{report.employees.length}</p>
              </div>
            </div>
          </Card>

          <Card>
            <div className="flex items-center">
              <div className="flex-shrink-0">
                <TrendingUp className="h-8 w-8 text-green-600" />
              </div>
              <div className="ml-4">
                <p className="text-sm font-medium text-gray-600">Konsistensi {'>'}= 90%</p>
                <p className="text-2xl font-bold text-gray-900">
                  {report.employees.filter(e => e.consistency_rate >= 90).length}
                </p>
              </div>
            </div>
          </Card>

          <Card>
            <div className="flex items-center">
              <div className="flex-shrink-0">
                <Clock className="h-8 w-8 text-yellow-600" />
              </div>
              <div className="ml-4">
                <p className="text-sm font-medium text-gray-600">Rata-rata Konsistensi</p>
                <p className="text-2xl font-bold text-gray-900">
                  {(report.employees.reduce((sum, e) => sum + e.consistency_rate, 0) / report.employees.length).toFixed(1)}%
                </p>
              </div>
            </div>
          </Card>

          <Card>
            <div className="flex items-center">
              <div className="flex-shrink-0">
                <Calendar className="h-8 w-8 text-purple-600" />
              </div>
              <div className="ml-4">
                <p className="text-sm font-medium text-gray-600">Periode</p>
                <p className="text-sm font-bold text-gray-900">
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
          <h2 className="text-xl font-bold text-gray-900 mb-4">Daftar Karyawan</h2>
          <div className="space-y-4">
            {report.employees.map((employeeData) => {
              const isExpanded = expandedEmployees.has(employeeData.employee.id);
              const badge = getConsistencyBadge(employeeData.consistency_rate);

              return (
                <div
                  key={employeeData.employee.id}
                  className="border border-gray-200 rounded-lg overflow-hidden"
                >
                  {/* Employee Summary */}
                  <div
                    onClick={() => toggleEmployeeDetails(employeeData.employee.id)}
                    className="p-4 bg-gray-50 hover:bg-gray-100 cursor-pointer transition-colors"
                  >
                    <div className="flex items-center justify-between">
                      <div className="flex-1">
                        <h3 className="text-lg font-semibold text-gray-900">
                          {employeeData.employee.name}
                        </h3>
                        <p className="text-sm text-gray-600">{employeeData.employee.email}</p>
                      </div>

                      <div className="flex items-center gap-4">
                        <div className="text-right">
                          <p className="text-sm text-gray-600">Tepat Waktu</p>
                          <p className="text-lg font-bold text-green-600">
                            {employeeData.on_time_days} / {employeeData.total_days} hari
                          </p>
                        </div>

                        <div className="text-right">
                          <p className="text-sm text-gray-600">Konsistensi</p>
                          <div className="flex items-center gap-2">
                            <p className="text-lg font-bold text-gray-900">
                              {employeeData.consistency_rate}%
                            </p>
                            <span className={`px-2 py-1 text-xs font-medium rounded ${badge.color}`}>
                              {badge.label}
                            </span>
                          </div>
                        </div>

                        <button className="text-gray-400 hover:text-gray-600">
                          {isExpanded ? '▲' : '▼'}
                        </button>
                      </div>
                    </div>
                  </div>

                  {/* Employee Details */}
                  {isExpanded && (
                    <div className="p-4 bg-white">
                      <h4 className="font-semibold text-gray-900 mb-3">Detail Harian</h4>
                      <div className="overflow-x-auto">
                        <table className="min-w-full divide-y divide-gray-200">
                          <thead className="bg-gray-50">
                            <tr>
                              <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">
                                Tanggal
                              </th>
                              <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">
                                Jam Check-In
                              </th>
                              <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">
                                Status
                              </th>
                            </tr>
                          </thead>
                          <tbody className="bg-white divide-y divide-gray-200">
                            {employeeData.details.map((detail, index) => (
                              <tr key={index} className={detail.is_on_time ? 'bg-green-50' : 'bg-red-50'}>
                                <td className="px-4 py-3 text-sm text-gray-900">
                                  {formatDate(detail.date)}
                                </td>
                                <td className="px-4 py-3 text-sm font-medium text-gray-900">
                                  {detail.check_in_time}
                                </td>
                                <td className="px-4 py-3">
                                  <span
                                    className={`px-2 py-1 text-xs font-medium rounded ${
                                      detail.is_on_time
                                        ? 'bg-green-100 text-green-800'
                                        : 'bg-red-100 text-red-800'
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
            <div className="text-center py-12">
              <Clock className="mx-auto h-12 w-12 text-gray-400" />
              <h3 className="mt-2 text-sm font-medium text-gray-900">Tidak ada data</h3>
              <p className="mt-1 text-sm text-gray-500">
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
