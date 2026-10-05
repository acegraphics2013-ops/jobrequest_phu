'use client';

import React from 'react';
import Link from 'next/link';
import { formatDate } from '@/lib/utils';
import { StatusBadge } from '@/components/ui/StatusBadge';
import { Progress } from '@/components/ui/Progress';
import {
  Eye,
  Edit,
  Search,
  Filter,
  ChevronLeft,
  ChevronRight,
  Plus,
  Calendar,
  Users,
  Building,
  ArrowUpDown,
} from 'lucide-react';

interface ActivityTableProps {
  activities: any[];
  pagination: {
    page: number;
    limit: number;
    totalRecords: number;
    totalPages: number;
  };
  filters: {
    search: string;
    activity_type: string;
    ward_area: string;
    status: string;
    date_from: string;
    date_to: string;
    sort_by: string;
    order: string;
  };
  filterOptions: {
    wards: string[];
    activityTypes: string[];
    statuses: string[];
  };
  onFilterChange: (key: string, value: string) => void;
  onPageChange: (page: number) => void;
  loading?: boolean;
}

export function ActivityTable({
  activities,
  pagination,
  filters,
  filterOptions,
  onFilterChange,
  onPageChange,
  loading = false,
}: ActivityTableProps) {
  return (
    <div className="space-y-4">
      {/* Filter Bar */}
      <div className="rounded-xl border border-slate-200 bg-white p-4 shadow-xs">
        <div className="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
          {/* Search Box */}
          <div className="relative flex-1 min-w-[240px]">
            <Search className="absolute left-3 top-2.5 h-4 w-4 text-slate-400" />
            <input
              type="text"
              placeholder="Search by title, topic, ward, office, facilitator..."
              value={filters.search}
              onChange={(e) => onFilterChange('search', e.target.value)}
              className="w-full rounded-lg border border-slate-300 bg-white pl-9 pr-3.5 py-2 text-sm text-slate-900 placeholder:text-slate-400 focus:border-blue-500 focus:ring-1 focus:ring-blue-500"
            />
          </div>

          {/* Dropdown Filters */}
          <div className="flex flex-wrap items-center gap-2.5">
            <select
              value={filters.activity_type}
              onChange={(e) => onFilterChange('activity_type', e.target.value)}
              className="rounded-lg border border-slate-300 bg-white px-3 py-2 text-xs font-medium text-slate-700 focus:border-blue-500 focus:ring-1 focus:ring-blue-500"
            >
              <option value="">All Activity Types</option>
              {filterOptions.activityTypes?.map((t) => (
                <option key={t} value={t}>
                  {t}
                </option>
              ))}
            </select>

            <select
              value={filters.ward_area}
              onChange={(e) => onFilterChange('ward_area', e.target.value)}
              className="rounded-lg border border-slate-300 bg-white px-3 py-2 text-xs font-medium text-slate-700 focus:border-blue-500 focus:ring-1 focus:ring-blue-500"
            >
              <option value="">All Wards / Areas</option>
              {filterOptions.wards?.map((w) => (
                <option key={w} value={w}>
                  {w}
                </option>
              ))}
            </select>

            <select
              value={filters.status}
              onChange={(e) => onFilterChange('status', e.target.value)}
              className="rounded-lg border border-slate-300 bg-white px-3 py-2 text-xs font-medium text-slate-700 focus:border-blue-500 focus:ring-1 focus:ring-blue-500"
            >
              <option value="">All Statuses</option>
              {filterOptions.statuses?.map((s) => (
                <option key={s} value={s}>
                  {s}
                </option>
              ))}
            </select>

            <div className="flex items-center gap-1">
              <input
                type="date"
                value={filters.date_from}
                onChange={(e) => onFilterChange('date_from', e.target.value)}
                title="Date From"
                className="rounded-lg border border-slate-300 bg-white px-2.5 py-1.5 text-xs text-slate-700 focus:border-blue-500"
              />
              <span className="text-xs text-slate-400">to</span>
              <input
                type="date"
                value={filters.date_to}
                onChange={(e) => onFilterChange('date_to', e.target.value)}
                title="Date To"
                className="rounded-lg border border-slate-300 bg-white px-2.5 py-1.5 text-xs text-slate-700 focus:border-blue-500"
              />
            </div>
          </div>
        </div>
      </div>

      {/* Desktop Data Table */}
      <div className="hidden md:block rounded-xl border border-slate-200 bg-white overflow-hidden shadow-xs">
        <div className="overflow-x-auto">
          <table className="w-full text-left text-sm">
            <thead className="border-b border-slate-200 bg-slate-50/80 text-xs font-bold uppercase tracking-wider text-slate-500">
              <tr>
                <th className="px-4 py-3.5">ID</th>
                <th className="px-4 py-3.5">Date & Time</th>
                <th className="px-4 py-3.5">Activity Type</th>
                <th className="px-4 py-3.5">Title & Topic</th>
                <th className="px-4 py-3.5">Ward / Office</th>
                <th className="px-4 py-3.5 text-center">Participants</th>
                <th className="px-4 py-3.5 text-center">Doc %</th>
                <th className="px-4 py-3.5">Status</th>
                <th className="px-4 py-3.5 text-right">Actions</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-slate-100">
              {loading ? (
                Array.from({ length: 5 }).map((_, i) => (
                  <tr key={i} className="animate-pulse">
                    <td colSpan={9} className="px-4 py-4">
                      <div className="h-4 bg-slate-200 rounded w-full" />
                    </td>
                  </tr>
                ))
              ) : activities.length === 0 ? (
                <tr>
                  <td colSpan={9} className="px-4 py-12 text-center text-slate-500">
                    No activity records found matching your filters.
                  </td>
                </tr>
              ) : (
                activities.map((item) => (
                  <tr key={item.id} className="hover:bg-slate-50/80 transition-colors">
                    <td className="px-4 py-3.5 font-bold text-slate-600">#{item.id}</td>
                    <td className="px-4 py-3.5 whitespace-nowrap">
                      <p className="font-semibold text-slate-900">{formatDate(item.session_date)}</p>
                      <p className="text-xs text-slate-500">{item.activity_time || 'Not set'}</p>
                    </td>
                    <td className="px-4 py-3.5">
                      <span className="inline-block rounded-md bg-slate-100 px-2.5 py-1 text-xs font-medium text-slate-700">
                        {item.activity_type}
                      </span>
                    </td>
                    <td className="px-4 py-3.5 max-w-xs">
                      <p className="font-bold text-slate-900 line-clamp-1">{item.title}</p>
                      <p className="text-xs text-slate-500 line-clamp-1">Topic: {item.health_topic}</p>
                    </td>
                    <td className="px-4 py-3.5 whitespace-nowrap">
                      <p className="font-semibold text-slate-800">{item.ward_area}</p>
                      <p className="text-xs text-slate-500">{item.lead_office}</p>
                    </td>
                    <td className="px-4 py-3.5 text-center font-bold text-slate-900 whitespace-nowrap">
                      {item.participant_count}
                    </td>
                    <td className="px-4 py-3.5 w-28">
                      <Progress value={item.documentation_percentage} showLabel={false} size="sm" />
                      <p className="text-[10px] text-slate-500 text-center mt-1 font-semibold">
                        {item.documentation_percentage}%
                      </p>
                    </td>
                    <td className="px-4 py-3.5 whitespace-nowrap">
                      <StatusBadge status={item.status} size="sm" />
                    </td>
                    <td className="px-4 py-3.5 text-right whitespace-nowrap">
                      <div className="flex items-center justify-end gap-1.5">
                        <Link
                          href={`/health-education/activities/${item.id}`}
                          className="inline-flex items-center gap-1 rounded-md border border-slate-200 bg-white px-2.5 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-50 hover:text-blue-600 transition-colors"
                        >
                          <Eye className="h-3.5 w-3.5" />
                          View
                        </Link>
                      </div>
                    </td>
                  </tr>
                ))
              )}
            </tbody>
          </table>
        </div>
      </div>

      {/* Mobile Compact Cards View */}
      <div className="block md:hidden space-y-3">
        {loading ? (
          <div className="p-4 bg-white rounded-xl border border-slate-200 animate-pulse h-32" />
        ) : activities.length === 0 ? (
          <div className="p-8 text-center text-slate-500 bg-white rounded-xl border border-slate-200">
            No activity records found matching filters.
          </div>
        ) : (
          activities.map((item) => (
            <div key={item.id} className="rounded-xl border border-slate-200 bg-white p-4 shadow-xs space-y-3">
              <div className="flex items-start justify-between">
                <div>
                  <span className="text-xs font-bold text-slate-400">#{item.id}</span>
                  <h4 className="font-bold text-slate-900 line-clamp-2">{item.title}</h4>
                </div>
                <StatusBadge status={item.status} size="sm" />
              </div>

              <div className="grid grid-cols-2 gap-2 text-xs text-slate-600 border-t border-b border-slate-100 py-2">
                <div>
                  <span className="text-slate-400 block">Date</span>
                  <span className="font-semibold text-slate-800">{formatDate(item.session_date)}</span>
                </div>
                <div>
                  <span className="text-slate-400 block">Ward</span>
                  <span className="font-semibold text-slate-800">{item.ward_area}</span>
                </div>
                <div>
                  <span className="text-slate-400 block">Participants</span>
                  <span className="font-semibold text-slate-800">{item.participant_count}</span>
                </div>
                <div>
                  <span className="text-slate-400 block">Doc %</span>
                  <span className="font-semibold text-slate-800">{item.documentation_percentage}%</span>
                </div>
              </div>

              <div className="flex items-center justify-between pt-1">
                <span className="text-xs font-medium text-slate-500">{item.activity_type}</span>
                <Link
                  href={`/health-education/activities/${item.id}`}
                  className="inline-flex items-center gap-1 rounded-lg bg-blue-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-blue-700"
                >
                  <Eye className="h-3.5 w-3.5" />
                  Details
                </Link>
              </div>
            </div>
          ))
        )}
      </div>

      {/* Pagination Bar */}
      <div className="flex flex-col sm:flex-row items-center justify-between gap-3 border-t border-slate-200 pt-4 px-1">
        <p className="text-xs text-slate-500">
          Showing <span className="font-semibold text-slate-900">{activities.length}</span> of{' '}
          <span className="font-semibold text-slate-900">{pagination.totalRecords}</span> records
        </p>

        <div className="flex items-center gap-2">
          <button
            onClick={() => onPageChange(pagination.page - 1)}
            disabled={pagination.page <= 1 || loading}
            className="inline-flex items-center gap-1 rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-50 disabled:opacity-40"
          >
            <ChevronLeft className="h-4 w-4" />
            Previous
          </button>
          <span className="text-xs font-semibold text-slate-700 px-2">
            Page {pagination.page} of {pagination.totalPages}
          </span>
          <button
            onClick={() => onPageChange(pagination.page + 1)}
            disabled={pagination.page >= pagination.totalPages || loading}
            className="inline-flex items-center gap-1 rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-50 disabled:opacity-40"
          >
            Next
            <ChevronRight className="h-4 w-4" />
          </button>
        </div>
      </div>
    </div>
  );
}
