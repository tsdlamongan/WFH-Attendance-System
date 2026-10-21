import { useState, useEffect, useRef, useMemo } from 'react';
import { MainLayout } from '../../components/layout/MainLayout';
import { Card } from '../../components/common/Card';
import { Button } from '../../components/common/Button';
import { Loading } from '../../components/common/Loading';
import { getEvaluationReport, downloadEvaluationReportPdf } from '../../api/report.api';
import { getAllTeams } from '../../api/team.api';
import { getMonthStart, getTodayDate } from '../../utils/dateHelpers';
import { usePageTitle } from '../../hooks/usePageTitle';
import { Download, Users, FolderKanban, FileSearch } from 'lucide-react';
import toast from 'react-hot-toast';

const errorMessage = (error, fallback) => {
  const errors = error.response?.data?.errors;
  return errors ? Object.values(errors).flat().join(' ') : error.response?.data?.message || fallback;
};

const formatPercentage = (value) =>
  value === null || value === undefined ? '-' : `${String(value).replace('.', ',')}%`;

const SummaryCard = ({ label, value }) => (
  <Card>
    <p className="caption-uppercase mb-1">{label}</p>
    <p className="font-display text-display-md text-ink">{value}</p>
  </Card>
);

/**
 * Evaluation report of one or more teams for a period, merged into a single report: an on-screen
 * preview of the data and the PDF download (cover, staff summary + table, work summary + project
 * table). Nothing is generated until the admin picked the teams and the period.
 */
export const EvaluationReport = () => {
  usePageTitle('Laporan Evaluasi');
  const [teamsLoading, setTeamsLoading] = useState(true);
  const [teams, setTeams] = useState([]);
  const [selectedTeamIds, setSelectedTeamIds] = useState([]);
  const [startDate, setStartDate] = useState(getMonthStart());
  const [endDate, setEndDate] = useState(getTodayDate());
  const [loading, setLoading] = useState(false);
  const [downloading, setDownloading] = useState(false);
  const [report, setReport] = useState(null);
  // Ignore responses of superseded requests (the selection can change while a fetch is in flight).
  const requestIdRef = useRef(0);

  // Only the list of teams to choose from is loaded on open, never the report itself.
  useEffect(() => {
    const fetchTeams = async () => {
      try {
        const response = await getAllTeams(1000);
        if (response.success) {
          setTeams([...response.data].sort((a, b) => a.name.localeCompare(b.name)));
        }
      } catch (error) {
        console.error('Error fetching teams:', error);
        toast.error('Gagal mengambil data tim');
      } finally {
        setTeamsLoading(false);
      }
    };

    fetchTeams();
  }, []);

  const allSelected = teams.length > 0 && selectedTeamIds.length === teams.length;
  const noTeamSelected = selectedTeamIds.length === 0;
  const selectedTeams = useMemo(
    () => teams.filter((team) => selectedTeamIds.includes(team.id)),
    [teams, selectedTeamIds]
  );

  // The preview describes the teams and period it was generated for, so a changed selection drops it.
  const changeSelection = (apply) => {
    requestIdRef.current += 1;
    setLoading(false);
    setReport(null);
    apply();
  };

  const toggleTeam = (teamId) =>
    changeSelection(() =>
      setSelectedTeamIds((current) =>
        current.includes(teamId) ? current.filter((id) => id !== teamId) : [...current, teamId]
      )
    );

  const toggleAllTeams = () =>
    changeSelection(() => setSelectedTeamIds(allSelected ? [] : teams.map((team) => team.id)));

  const selectionIsValid = () => {
    if (noTeamSelected) {
      toast.error('Pilih minimal satu tim');
      return false;
    }
    if (!startDate || !endDate) {
      toast.error('Tanggal mulai dan tanggal akhir wajib diisi');
      return false;
    }
    if (endDate < startDate) {
      toast.error('Tanggal akhir tidak boleh sebelum tanggal mulai');
      return false;
    }
    return true;
  };

  const handleGenerate = async () => {
    if (!selectionIsValid()) return;

    const requestId = ++requestIdRef.current;
    try {
      setLoading(true);
      const response = await getEvaluationReport(selectedTeamIds, startDate, endDate);

      if (requestId === requestIdRef.current && response.success) {
        setReport(response.data);
      }
    } catch (error) {
      console.error('Error fetching evaluation report:', error);
      if (requestId === requestIdRef.current) {
        toast.error(errorMessage(error, 'Gagal mengambil laporan evaluasi'));
      }
    } finally {
      if (requestId === requestIdRef.current) {
        setLoading(false);
      }
    }
  };

  const handleDownload = async () => {
    if (!selectionIsValid()) return;

    try {
      setDownloading(true);
      const { blob, filename } = await downloadEvaluationReportPdf(selectedTeamIds, startDate, endDate);

      const url = URL.createObjectURL(blob);
      const link = document.createElement('a');
      link.href = url;
      link.download = filename;
      document.body.appendChild(link);
      link.click();
      link.remove();
      URL.revokeObjectURL(url);

      toast.success('Laporan evaluasi berhasil diunduh');
    } catch (error) {
      console.error('Error downloading evaluation report:', error);
      toast.error(errorMessage(error, 'Gagal mengunduh laporan evaluasi'));
    } finally {
      setDownloading(false);
    }
  };

  const staff = report?.human_resources;
  const work = report?.projects;

  return (
    <MainLayout>
      <div className="space-y-6 sm:space-y-8">
        {/* Header */}
        <div>
          <h1 className="text-display-md sm:text-display-lg">Laporan Evaluasi</h1>
          <p className="mt-2 font-serif text-body">
            Rekap jam kerja SDM dan laporan pekerjaan per project dari satu atau beberapa tim, digabung
            menjadi satu laporan dan siap diunduh sebagai PDF
          </p>
        </div>

        {/* Teams + period + download */}
        <Card>
          <div className="mb-4 flex items-center justify-between gap-4">
            <p className="caption-uppercase">Tim yang Disertakan</p>
            {teams.length > 0 && (
              <button
                type="button"
                onClick={toggleAllTeams}
                className="inline-link font-serif text-sm"
              >
                {allSelected ? 'Kosongkan pilihan' : 'Pilih semua tim'}
              </button>
            )}
          </div>

          {teamsLoading ? (
            <Loading />
          ) : teams.length === 0 ? (
            <p className="font-serif text-body text-muted">Belum ada tim.</p>
          ) : (
            <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3" data-testid="evaluation-team-list">
              {teams.map((team) => {
                const checked = selectedTeamIds.includes(team.id);
                return (
                  <label
                    key={team.id}
                    className={`flex cursor-pointer items-center gap-3 rounded-xl border-2 px-4 py-3 transition-colors ${
                      checked ? 'border-ink bg-surface-soft' : 'border-hairline hover:border-ink'
                    }`}
                  >
                    <input
                      type="checkbox"
                      checked={checked}
                      onChange={() => toggleTeam(team.id)}
                      className="h-4 w-4 shrink-0 rounded-sm accent-primary"
                    />
                    <span className="min-w-0 flex-1 truncate font-serif text-body-strong">{team.name}</span>
                    {!team.is_active && <span className="badge badge-muted">Nonaktif</span>}
                  </label>
                );
              })}
            </div>
          )}

          <div className="mt-6 flex flex-col gap-4 lg:flex-row lg:items-end">
            <div className="w-full lg:flex-1">
              <label htmlFor="evaluation-start-date" className="caption-uppercase block mb-2">
                Tanggal Mulai
              </label>
              <input
                id="evaluation-start-date"
                type="date"
                value={startDate}
                onChange={(e) => changeSelection(() => setStartDate(e.target.value))}
                className="input-field w-full"
              />
            </div>
            <div className="w-full lg:flex-1">
              <label htmlFor="evaluation-end-date" className="caption-uppercase block mb-2">
                Tanggal Akhir
              </label>
              <input
                id="evaluation-end-date"
                type="date"
                value={endDate}
                onChange={(e) => changeSelection(() => setEndDate(e.target.value))}
                className="input-field w-full"
              />
            </div>
            <div className="flex flex-col gap-3 sm:flex-row">
              <Button
                variant="secondary"
                className="w-full sm:w-auto"
                onClick={handleGenerate}
                disabled={noTeamSelected || loading}
              >
                {loading ? 'Memproses...' : 'Tampilkan'}
              </Button>
              <Button
                className="w-full sm:w-auto"
                onClick={handleDownload}
                disabled={noTeamSelected || downloading}
              >
                <Download size={20} />
                <span>{downloading ? 'Menyiapkan PDF...' : 'Download PDF'}</span>
              </Button>
            </div>
          </div>
          <p className="mt-4 font-serif text-sm text-muted">
            {noTeamSelected
              ? 'Pilih minimal satu tim dan periode, lalu klik Tampilkan untuk melihat pratinjau atau Download PDF untuk mengunduh. '
              : `${selectedTeams.length} tim dipilih. `}
            Jam kerja dan status SDM dihitung dari data check-in (cuti disetujui ikut dihitung) terhadap jam
            kerja semestinya tim masing-masing. Kolom Problem berisi pekerjaan yang diisi karyawan untuk tiap
            project pada periode ini; PIC, progress dan catatan diatur manager tiap tim di halaman Project,
            jabatan dan status karyawan di halaman Pengguna.
          </p>
        </Card>

        {loading ? (
          <Loading />
        ) : !report ? (
          <Card>
            <div className="text-center py-16">
              <FileSearch size={48} className="mx-auto text-muted mb-4" />
              <p className="caption-uppercase">Laporan belum dibuat</p>
              <p className="mt-2 font-serif text-sm text-muted">
                Pilih tim dan periode, lalu klik Tampilkan untuk membuat laporan.
              </p>
            </div>
          </Card>
        ) : (
          <>
            {/* Cover info */}
            <Card>
              <p className="caption-uppercase mb-1">Laporan Evaluasi</p>
              <p className="font-display text-display-md text-ink">{report.title}</p>
              <p className="mt-1 font-serif text-body">Periode: {report.period.label}</p>
              <p className="mt-1 font-serif text-sm text-muted">
                Tim: {report.teams.map((team) => team.name).join(', ')}
              </p>
            </Card>

            {/* Staff summary */}
            <div className="grid grid-cols-2 gap-4 sm:gap-6 lg:grid-cols-4">
              <SummaryCard label="Total SDM" value={staff.summary.total} />
              <SummaryCard label="Karyawan Tetap" value={staff.summary.permanent} />
              <SummaryCard label="Karyawan Intern" value={staff.summary.intern} />
              <SummaryCard label="Persentase Kehadiran" value={formatPercentage(staff.summary.attendance_percentage)} />
            </div>

            {/* Staff table */}
            <Card title="Laporan SDM">
              {staff.rows.length === 0 ? (
                <div className="text-center py-16">
                  <Users size={48} className="mx-auto text-muted mb-4" />
                  <p className="caption-uppercase">Belum ada SDM di tim yang dipilih</p>
                </div>
              ) : (
                <>
                  <p className="-mt-3 mb-4 font-serif text-sm text-muted">
                    {staff.teams.map((team) => (
                      <span key={team.id} className="block">
                        {staff.teams.length > 1 && `${team.name}: `}
                        {team.working_days} hari kerja, {team.expected_hours} jam kerja semestinya per karyawan
                      </span>
                    ))}
                  </p>
                  <div className="rounded-xl border-2 border-ink overflow-hidden">
                    <div className="overflow-x-auto">
                      <table className="w-full" data-testid="evaluation-staff-table">
                        <thead>
                          <tr className="bg-surface-soft border-b-2 border-ink">
                            <th className="caption-uppercase text-left px-4 py-3">No</th>
                            <th className="caption-uppercase text-left px-4 py-3">Nama</th>
                            <th className="caption-uppercase text-left px-4 py-3">Jabatan</th>
                            <th className="caption-uppercase text-left px-4 py-3">Jumlah Jam Kerja</th>
                            <th className="caption-uppercase text-left px-4 py-3">Status</th>
                          </tr>
                        </thead>
                        <tbody>
                          {staff.rows.map((row) => (
                            <tr key={row.no} className="border-b border-hairline last:border-0 hover:bg-surface-soft transition-colors">
                              <td className="px-4 py-3 font-mono text-sm text-muted">{row.no}</td>
                              <td className="px-4 py-3 font-serif text-body-strong">{row.name}</td>
                              <td className="px-4 py-3 font-serif text-sm text-body">{row.job_title}</td>
                              <td className="px-4 py-3 whitespace-nowrap font-mono text-sm text-ink">{row.hours_label || '-'}</td>
                              <td className="px-4 py-3 whitespace-nowrap font-display text-sm font-bold" style={{ color: row.rating_color || undefined }}>
                                {row.rating || '-'}
                              </td>
                            </tr>
                          ))}
                        </tbody>
                      </table>
                    </div>
                  </div>
                </>
              )}
            </Card>

            {/* Work summary */}
            <div className="grid grid-cols-2 gap-4 sm:gap-6 lg:grid-cols-4">
              <SummaryCard label="Total Project Planned" value={work.summary.total} />
              <SummaryCard label="Project Selesai" value={work.summary.completed} />
              <SummaryCard label="Project On-Progress" value={work.summary.on_progress} />
              <SummaryCard label="Project Hold" value={work.summary.hold} />
            </div>

            {/* Project table */}
            <Card title="Laporan Pekerjaan">
              {work.rows.length === 0 ? (
                <div className="text-center py-16">
                  <FolderKanban size={48} className="mx-auto text-muted mb-4" />
                  <p className="caption-uppercase">Belum ada project di tim yang dipilih</p>
                </div>
              ) : (
                <>
                  {work.rows.some((row) => row.problem_summarized) && (
                    <p className="-mt-3 mb-4 font-serif text-sm text-muted">
                      Kolom Problem yang panjang diringkas otomatis oleh Gemini; daftar pekerjaan aslinya bisa dibuka di tiap baris.
                      PDF memakai ringkasan yang sama.
                    </p>
                  )}
                <div className="rounded-xl border-2 border-ink overflow-hidden">
                  <div className="overflow-x-auto">
                    <table className="w-full" data-testid="evaluation-project-table">
                      <thead>
                        <tr className="bg-surface-soft border-b-2 border-ink">
                          <th className="caption-uppercase text-left px-4 py-3">No</th>
                          <th className="caption-uppercase text-left px-4 py-3">Nama Project</th>
                          <th className="caption-uppercase text-left px-4 py-3">PIC</th>
                          <th className="caption-uppercase text-left px-4 py-3">Update</th>
                          <th className="caption-uppercase text-left px-4 py-3">Problem</th>
                          <th className="caption-uppercase text-left px-4 py-3">Note</th>
                        </tr>
                      </thead>
                      <tbody>
                        {work.rows.map((row) => (
                          <tr key={row.id} className="border-b border-hairline last:border-0 hover:bg-surface-soft transition-colors align-top">
                            <td className="px-4 py-3 font-mono text-sm text-muted">{row.no}</td>
                            <td className="px-4 py-3 min-w-40 font-serif text-body-strong">{row.name}</td>
                            <td className="px-4 py-3 min-w-32 font-serif text-sm text-body">{row.pic}</td>
                            <td className="px-4 py-3 whitespace-nowrap font-mono text-sm text-ink">{row.progress}%</td>
                            <td className="px-4 py-3 min-w-64 font-serif text-sm text-body">
                              {row.problem}
                              {row.problem_summarized && (
                                <details className="mt-2">
                                  <summary className="cursor-pointer caption-uppercase">Ringkasan AI · daftar asli</summary>
                                  <p className="mt-1 font-serif text-xs text-muted">{row.problem_detail}</p>
                                </details>
                              )}
                            </td>
                            <td className="px-4 py-3 min-w-36 font-serif text-sm text-body">{row.note}</td>
                          </tr>
                        ))}
                      </tbody>
                    </table>
                  </div>
                </div>
                </>
              )}
            </Card>
          </>
        )}
      </div>
    </MainLayout>
  );
};
