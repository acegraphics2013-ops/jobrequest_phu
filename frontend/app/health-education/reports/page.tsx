'use client';

import React, { useEffect, useState } from 'react';
import { fetchApi } from '@/lib/api';
import { StatCard } from '@/components/ui/StatCard';
import { formatDate } from '@/lib/utils';
import { StatusBadge } from '@/components/ui/StatusBadge';
import {
  MonthlyActivitiesChart,
  ParticipantsTrendChart,
  HorizontalBarChart,
  ActivityTypesPieChart,
} from '@/components/charts/OverviewCharts';
import {
  ChartNoAxesCombined,
  Printer,
  Download,
  Filter,
  Calendar,
  Users,
  MapPin,
  ClipboardList,
  CheckCircle2,
  Send,
  BookOpen,
  FileText,
} from 'lucide-react';

export default function HealthEducationReportsPage() {
  const [loading, setLoading] = useState(true);
  const [reportData, setReportData] = useState<any>(null);

  const [filters, setFilters] = useState({
    date_from: '',
    date_to: '',
    activity_type: '',
    ward_area: '',
    lead_office: '',
    health_topic: '',
    status: '',
  });

  const loadReport = async (currentFilters = filters) => {
    setLoading(true);
    const query = new URLSearchParams(currentFilters as any).toString();
    const res = await fetchApi(`/health-education/reports.php?${query}`);
    if (res.success && res.data) {
      setReportData(res.data);
    }
    setLoading(false);
  };

  useEffect(() => {
    loadReport();
  }, []);

  const handleFilterChange = (key: string, value: string) => {
    const newFilters = { ...filters, [key]: value };
    setFilters(newFilters);
    loadReport(newFilters);
  };

  const handleExportCSV = () => {
    if (!reportData?.rows || reportData.rows.length === 0) return;

    const headers = ['ID', 'Title', 'Type', 'Date', 'Ward', 'Lead Office', 'Health Topic', 'Facilitator', 'Total Participants', 'Status'];
    const csvRows = [headers.join(',')];

    reportData.rows.forEach((row: any) => {
      const values = [
        row.id,
        `"${row.title.replace(/"/g, '""')}"`,
        `"${row.activity_type}"`,
        row.session_date,
        `"${row.ward_area}"`,
        `"${row.lead_office}"`,
        `"${row.health_topic}"`,
        `"${row.facilitator}"`,
        row.participant_count,
        row.status,
      ];
      csvRows.push(values.join(','));
    });

    const blob = new Blob([csvRows.join('\n')], { type: 'text/csv;charset=utf-8;' });
    const url = URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.href = url;
    link.setAttribute('download', `Health_Education_Report_${new Date().toISOString().split('T')[0]}.csv`);
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
  };

  const handlePrint = () => {
    window.print();
  };

  const summary = reportData?.summary || {};
  const charts = reportData?.charts || {};
  const rows = reportData?.rows || [];

  return (
    <div className="space-y-6 print:p-0 print:space-y-4">
      {/* Top Title & Export Action Bar */}
      <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4 border-b border-slate-200 pb-4 print:hidden">
        <div>
          <h1 className="text-2xl font-bold tracking-tight text-slate-900">
            Health Education Reports & Analytics
          </h1>
          <p className="text-sm text-slate-500">
            Generate custom reports, evaluate ward reach, participant engagement, and export data.
          </p>
        </div>

        <div className="flex items-center gap-2">
          <button
            onClick={handleExportCSV}
            className="inline-flex items-center gap-1.5 rounded-lg border border-slate-300 bg-white px-3.5 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-50 shadow-xs"
          >
            <Download className="h-4 w-4 text-emerald-600" />
            Export Excel / CSV
          </button>
          <button
            onClick={handlePrint}
            className="inline-flex items-center gap-1.5 rounded-lg bg-blue-600 px-4 py-2 text-xs font-semibold text-white shadow-xs hover:bg-blue-700"
          >
            <Printer className="h-4 w-4" />
            Print Report
          </button>
        </div>
      </div>

      {/* Filter Toolbar */}
      <div className="rounded-xl border border-slate-200 bg-white p-4 shadow-xs print:hidden">
        <div className="flex items-center gap-2 mb-3 text-xs font-bold uppercase tracking-wider text-slate-500">
          <Filter className="h-3.5 w-3.5 text-blue-600" />
          <span>Report Filters</span>
        </div>

        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 md:grid-cols-4">
          <div>
            <label className="block text-[11px] font-semibold text-slate-500 uppercase mb-1">Date From</label>
            <input
              type="date"
              value={filters.date_from}
              onChange={(e) => handleFilterChange('date_from', e.target.value)}
              className="w-full rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-xs text-slate-900 focus:border-blue-500"
            />
          </div>

          <div>
            <label className="block text-[11px] font-semibold text-slate-500 uppercase mb-1">Date To</label>
            <input
              type="date"
              value={filters.date_to}
              onChange={(e) => handleFilterChange('date_to', e.target.value)}
              className="w-full rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-xs text-slate-900 focus:border-blue-500"
            />
          </div>

          <div>
            <label className="block text-[11px] font-semibold text-slate-500 uppercase mb-1">Activity Type</label>
            <select
              value={filters.activity_type}
              onChange={(e) => handleFilterChange('activity_type', e.target.value)}
              className="w-full rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-xs text-slate-900 focus:border-blue-500"
            >
              <option value="">All Activity Types</option>
              <option value="Health Education and Promotion">Health Education and Promotion</option>
              <option value="Health Education Session / Lecture">Health Education Session / Lecture</option>
              <option value="Health Promotion Campaign / Advocacy">Health Promotion Campaign / Advocacy</option>
              <option value="Symposium / Health Forum">Symposium / Health Forum</option>
              <option value="Staff Health Education / Wellness Activity">Staff Health Education / Wellness Activity</option>
              <option value="Screening and Early Detection Activity">Screening and Early Detection Activity</option>
              <option value="Community-Based Health Promotion">Community-Based Health Promotion</option>
            </select>
          </div>

          <div>
            <label className="block text-[11px] font-semibold text-slate-500 uppercase mb-1">Status</label>
            <select
              value={filters.status}
              onChange={(e) => handleFilterChange('status', e.target.value)}
              className="w-full rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-xs text-slate-900 focus:border-blue-500"
            >
              <option value="">All Statuses</option>
              <option value="Approved">Approved</option>
              <option value="Published">Published</option>
              <option value="For Review">For Review</option>
              <option value="Conducted">Conducted</option>
              <option value="Archived">Archived</option>
            </select>
          </div>
        </div>
      </div>

      {/* Summary KPI Cards Grid */}
      <div className="grid grid-cols-2 gap-3.5 sm:grid-cols-3 md:grid-cols-6">
        <StatCard
          title="Total Activities"
          value={summary.total_activities || 0}
          icon={ClipboardList}
          iconColor="text-blue-600"
          iconBg="bg-blue-50"
        />
        <StatCard
          title="Total Audience"
          value={summary.total_participants || 0}
          icon={Users}
          iconColor="text-emerald-600"
          iconBg="bg-emerald-50"
        />
        <StatCard
          title="Patients Reached"
          value={summary.patients_count || 0}
          icon={Users}
          iconColor="text-teal-600"
          iconBg="bg-teal-50"
        />
        <StatCard
          title="Wards Covered"
          value={summary.wards_covered || 0}
          icon={MapPin}
          iconColor="text-sky-600"
          iconBg="bg-sky-50"
        />
        <StatCard
          title="Approved"
          value={summary.approved_count || 0}
          icon={CheckCircle2}
          iconColor="text-indigo-600"
          iconBg="bg-indigo-50"
        />
        <StatCard
          title="Published"
          value={summary.published_count || 0}
          icon={Send}
          iconColor="text-purple-600"
          iconBg="bg-purple-50"
        />
      </div>

      {/* Charts Section Grid */}
      <div className="grid grid-cols-1 gap-6 lg:grid-cols-2">
        <div className="rounded-xl border border-slate-200 bg-white p-5 shadow-xs">
          <h3 className="text-base font-bold text-slate-900 border-b border-slate-100 pb-3 mb-4">
            Monthly Activity & Participant Trend
          </h3>
          <MonthlyActivitiesChart data={charts.monthlyTrend} />
        </div>

        <div className="rounded-xl border border-slate-200 bg-white p-5 shadow-xs">
          <h3 className="text-base font-bold text-slate-900 border-b border-slate-100 pb-3 mb-4">
            Ward & Area Coverage
          </h3>
          <HorizontalBarChart data={charts.wardCoverage} nameKey="ward" />
        </div>
      </div>

      {/* Report Rows Summary Table */}
      <div className="rounded-xl border border-slate-200 bg-white p-5 shadow-xs">
        <div className="flex items-center justify-between border-b border-slate-100 pb-4 mb-4">
          <div>
            <h3 className="text-base font-bold text-slate-900">Activity Report Log</h3>
            <p className="text-xs text-slate-500">Summary list matching current report filters</p>
          </div>
          <span className="text-xs font-semibold text-slate-500">{rows.length} records</span>
        </div>

        <div className="overflow-x-auto">
          <table className="w-full text-left text-xs">
            <thead className="border-b border-slate-200 bg-slate-50 font-bold uppercase tracking-wider text-slate-500">
              <tr>
                <th className="px-3 py-2.5">ID</th>
                <th className="px-3 py-2.5">Date</th>
                <th className="px-3 py-2.5">Type</th>
                <th className="px-3 py-2.5">Title & Topic</th>
                <th className="px-3 py-2.5">Ward / Office</th>
                <th className="px-3 py-2.5 text-center">Audience</th>
                <th className="px-3 py-2.5">Status</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-slate-100">
              {rows.length === 0 ? (
                <tr>
                  <td colSpan={7} className="px-3 py-8 text-center text-slate-400">
                    No records found matching filters.
                  </td>
                </tr>
              ) : (
                rows.map((row: any) => (
                  <tr key={row.id} className="hover:bg-slate-50">
                    <td className="px-3 py-2.5 font-bold text-slate-600">#{row.id}</td>
                    <td className="px-3 py-2.5 whitespace-nowrap">{formatDate(row.session_date)}</td>
                    <td className="px-3 py-2.5 font-medium text-slate-700">{row.activity_type}</td>
                    <td className="px-3 py-2.5 max-w-xs">
                      <p className="font-bold text-slate-900 truncate">{row.title}</p>
                      <p className="text-slate-500 truncate">{row.health_topic}</p>
                    </td>
                    <td className="px-3 py-2.5 whitespace-nowrap">
                      <p className="font-semibold text-slate-800">{row.ward_area}</p>
                      <p className="text-slate-400">{row.lead_office}</p>
                    </td>
                    <td className="px-3 py-2.5 text-center font-bold text-slate-900">{row.participant_count}</td>
                    <td className="px-3 py-2.5 whitespace-nowrap">
                      <StatusBadge status={row.status} size="sm" />
                    </td>
                  </tr>
                ))
              )}
            </tbody>
          </table>
        </div>
      </div>
    </div>
  );
}
