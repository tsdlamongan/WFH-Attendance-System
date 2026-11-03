import apiClient from './axios';

export const getMyReport = async (startDate, endDate) => {
  const response = await apiClient.get('/reports/my-report', {
    params: { start_date: startDate, end_date: endDate },
  });
  return response.data;
};

// Super Admin - Evaluation report (staff hours + work per project) of one or more teams merged
// into one report, for a period: JSON preview and PDF. The optional title replaces the one the
// backend makes from the team names (a blank title keeps that default).
export const getEvaluationReport = async (teamIds, startDate, endDate, title) => {
  const response = await apiClient.get('/super-admin/reports/evaluation', {
    params: { team_ids: teamIds, start_date: startDate, end_date: endDate, title: title?.trim() || undefined },
  });
  return response.data;
};

// Resolves to { blob, filename }. On failure the JSON error body is restored on
// error.response.data, so callers read error.response.data.message as usual.
export const downloadEvaluationReportPdf = async (teamIds, startDate, endDate, title) => {
  try {
    const response = await apiClient.get('/super-admin/reports/evaluation/pdf', {
      params: { team_ids: teamIds, start_date: startDate, end_date: endDate, title: title?.trim() || undefined },
      responseType: 'blob',
    });
    const disposition = response.headers['content-disposition'] || '';
    const filename = disposition.match(/filename="?([^";]+)"?/)?.[1]
      || `laporan-evaluasi-${startDate}-sd-${endDate}.pdf`;
    return { blob: response.data, filename };
  } catch (error) {
    if (error.response?.data instanceof Blob) {
      try {
        error.response.data = JSON.parse(await error.response.data.text());
      } catch {
        error.response.data = {};
      }
    }
    throw error;
  }
};
