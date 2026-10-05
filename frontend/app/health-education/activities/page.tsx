'use client';

import React, { useEffect, useState } from 'react';
import Link from 'next/link';
import { fetchApi } from '@/lib/api';
import { ActivityTable } from '@/components/health-education/ActivityTable';
import { Plus, ClipboardList } from 'lucide-react';

export default function ActivitiesPage() {
  const [loading, setLoading] = useState(true);
  const [activities, setActivities] = useState<any[]>([]);
  const [pagination, setPagination] = useState({
    page: 1,
    limit: 10,
    totalRecords: 0,
    totalPages: 1,
  });

  const [filters, setFilters] = useState({
    search: '',
    activity_type: '',
    ward_area: '',
    status: '',
    date_from: '',
    date_to: '',
    sort_by: 'session_date',
    order: 'DESC',
  });

  const [filterOptions, setFilterOptions] = useState({
    wards: [],
    activityTypes: [],
    statuses: [],
  });

  const loadActivities = async (page = 1, currentFilters = filters) => {
    setLoading(true);
    const query = new URLSearchParams({
      page: String(page),
      limit: String(pagination.limit),
      search: currentFilters.search,
      activity_type: currentFilters.activity_type,
      ward_area: currentFilters.ward_area,
      status: currentFilters.status,
      date_from: currentFilters.date_from,
      date_to: currentFilters.date_to,
      sort_by: currentFilters.sort_by,
      order: currentFilters.order,
    }).toString();

    const res = await fetchApi(`/health-education/activities.php?${query}`);
    if (res.success && res.data) {
      setActivities(res.data.activities || []);
      setPagination(res.data.pagination);
      if (res.data.filterOptions) {
        setFilterOptions(res.data.filterOptions);
      }
    }
    setLoading(false);
  };

  useEffect(() => {
    loadActivities(1, filters);
  }, []);

  const handleFilterChange = (key: string, value: string) => {
    const newFilters = { ...filters, [key]: value };
    setFilters(newFilters);
    loadActivities(1, newFilters);
  };

  const handlePageChange = (newPage: number) => {
    loadActivities(newPage, filters);
  };

  return (
    <div className="space-y-6">
      {/* Header Bar */}
      <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4 border-b border-slate-200 pb-4">
        <div>
          <h1 className="text-2xl font-bold tracking-tight text-slate-900">
            Health Education Activities
          </h1>
          <p className="text-sm text-slate-500">
            Browse, filter, and manage all conducted ward health education sessions and health promotion activities.
          </p>
        </div>

        <Link
          href="/health-education/new"
          className="inline-flex items-center gap-2 rounded-lg bg-blue-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-blue-700 transition-colors shrink-0"
        >
          <Plus className="h-4 w-4" />
          New Activity
        </Link>
      </div>

      {/* Main Table Component */}
      <ActivityTable
        activities={activities}
        pagination={pagination}
        filters={filters}
        filterOptions={filterOptions}
        onFilterChange={handleFilterChange}
        onPageChange={handlePageChange}
        loading={loading}
      />
    </div>
  );
}
