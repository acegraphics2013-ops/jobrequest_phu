'use client';

import React, { useEffect, useState } from 'react';
import { useAuth } from '@/lib/auth-context';
import { fetchApi } from '@/lib/api';
import { JobRequest, Pagination } from '@/types';
import { Sidebar, SidebarLayout } from '@/components/layout/Sidebar';
import { Navbar } from '@/components/layout/Navbar';
import { StatusBadge } from '@/components/ui/StatusBadge';
import Link from 'next/link';
import { Search, Filter, PlusCircle, ArrowLeft, ArrowRight } from 'lucide-react';

export default function RequestsPage() {
  const { user, loading: authLoading } = useAuth();
  const [requests, setRequests] = useState<JobRequest[]>([]);
  const [pagination, setPagination] = useState<Pagination>({ total: 0, page: 1, limit: 10, total_pages: 1 });
  const [search, setSearch] = useState('');
  const [statusFilter, setStatusFilter] = useState('');
  const [loading, setLoading] = useState(true);

  const loadRequests = async (pageNum = 1) => {
    setLoading(true);
    const query = new URLSearchParams({
      page: pageNum.toString(),
      limit: '10',
      search: search,
      status: statusFilter,
    });

    const res = await fetchApi<{ requests: JobRequest[]; pagination: Pagination }>(
      `/requests/index.php?${query.toString()}`
    );

    if (res.success && res.data) {
      setRequests(res.data.requests || []);
      setPagination(res.data.pagination || { total: 0, page: 1, limit: 10, total_pages: 1 });
    }
    setLoading(false);
  };

  useEffect(() => {
    if (user) {
      loadRequests(1);
    }
  }, [user, statusFilter]);

  const handleSearchSubmit = (e: React.FormEvent) => {
    e.preventDefault();
    loadRequests(1);
  };

  return (
    <SidebarLayout>
      <Sidebar />
      <div className="flex-1 flex flex-col min-w-0">
        <Navbar />

        <main className="p-6 space-y-6 max-w-7xl mx-auto w-full">
          {/* Header toolbar */}
          <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
            <div>
              <h1 className="text-xl font-bold text-slate-900 tracking-tight">Job Requests</h1>
              <p className="text-xs text-slate-500">Manage and track all submitted job requests</p>
            </div>
            <Link
              href="/requests/new"
              className="bg-teal-600 hover:bg-teal-500 text-white text-xs font-medium px-4 py-2.5 rounded-xl inline-flex items-center gap-2 shadow-xs transition-all w-fit"
            >
              <PlusCircle className="w-4 h-4" />
              <span>Create Request</span>
            </Link>
          </div>

          {/* Filter & Search Toolbar */}
          <div className="bg-white p-4 rounded-2xl border border-slate-200 shadow-xs flex flex-col md:flex-row md:items-center justify-between gap-4">
            <form onSubmit={handleSearchSubmit} className="flex-1 flex items-center gap-2">
              <div className="relative flex-1 max-w-md">
                <Search className="w-4 h-4 absolute left-3.5 top-2.5 text-slate-400" />
                <input
                  type="text"
                  value={search}
                  onChange={(e) => setSearch(e.target.value)}
                  placeholder="Search by control #, requester, type..."
                  className="w-full bg-slate-50 border border-slate-200 text-xs rounded-xl pl-10 pr-4 py-2 focus:outline-none focus:border-teal-500"
                />
              </div>
              <button
                type="submit"
                className="bg-slate-900 hover:bg-slate-800 text-white text-xs font-medium px-4 py-2 rounded-xl"
              >
                Search
              </button>
            </form>

            <div className="flex items-center gap-2">
              <Filter className="w-4 h-4 text-slate-400" />
              <select
                value={statusFilter}
                onChange={(e) => setStatusFilter(e.target.value)}
                className="bg-slate-50 border border-slate-200 text-xs text-slate-700 rounded-xl px-3 py-2 focus:outline-none focus:border-teal-500"
              >
                <option value="">All Statuses</option>
                <option value="Pending">Pending</option>
                <option value="In Progress">In Progress</option>
                <option value="Approved">Approved</option>
                <option value="Completed">Completed</option>
                <option value="Rejected">Rejected</option>
              </select>
            </div>
          </div>

          {/* Data Table */}
          <div className="bg-white rounded-2xl border border-slate-200 shadow-xs overflow-hidden">
            <div className="overflow-x-auto">
              <table className="w-full text-left border-collapse">
                <thead>
                  <tr className="bg-slate-50 border-b border-slate-100 text-[11px] font-semibold text-slate-500 uppercase tracking-wider">
                    <th className="py-3.5 px-4">Control #</th>
                    <th className="py-3.5 px-4">Request Type</th>
                    <th className="py-3.5 px-4">Requester</th>
                    <th className="py-3.5 px-4">Department</th>
                    <th className="py-3.5 px-4">Priority</th>
                    <th className="py-3.5 px-4">Status</th>
                    <th className="py-3.5 px-4 text-right">Created Date</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-slate-100 text-xs text-slate-700">
                  {loading ? (
                    <tr>
                      <td colSpan={7} className="py-12 text-center text-slate-400">
                        Loading requests...
                      </td>
                    </tr>
                  ) : requests.length === 0 ? (
                    <tr>
                      <td colSpan={7} className="py-12 text-center text-slate-400">
                        No job requests found.
                      </td>
                    </tr>
                  ) : (
                    requests.map((req) => (
                      <tr key={req.id} className="hover:bg-slate-50/80 transition-colors">
                        <td className="py-3.5 px-4 font-semibold text-slate-900">
                          <Link href={`/requests/${req.id}`} className="hover:text-teal-600">
                            {req.control_number}
                          </Link>
                        </td>
                        <td className="py-3.5 px-4">{req.request_type}</td>
                        <td className="py-3.5 px-4 font-medium text-slate-800">{req.requester_name}</td>
                        <td className="py-3.5 px-4">{req.department}</td>
                        <td className="py-3.5 px-4">
                          <span
                            className={`px-2 py-0.5 rounded text-[10px] font-semibold ${req.priority === 'Urgent'
                                ? 'bg-rose-100 text-rose-700'
                                : req.priority === 'High'
                                  ? 'bg-amber-100 text-amber-700'
                                  : 'bg-slate-100 text-slate-600'
                              }`}
                          >
                            {req.priority || 'Normal'}
                          </span>
                        </td>
                        <td className="py-3.5 px-4">
                          <StatusBadge status={req.status} />
                        </td>
                        <td className="py-3.5 px-4 text-right text-slate-500">
                          {new Date(req.created_at).toLocaleDateString()}
                        </td>
                      </tr>
                    ))
                  )}
                </tbody>
              </table>
            </div>

            {/* Pagination Footer */}
            <div className="p-4 border-t border-slate-100 flex items-center justify-between text-xs text-slate-500">
              <span>
                Page {pagination.page} of {pagination.total_pages || 1} ({pagination.total} total requests)
              </span>
              <div className="flex items-center gap-2">
                <button
                  disabled={pagination.page <= 1}
                  onClick={() => loadRequests(pagination.page - 1)}
                  className="p-1.5 border border-slate-200 rounded-lg disabled:opacity-40 hover:bg-slate-50"
                >
                  <ArrowLeft className="w-4 h-4" />
                </button>
                <button
                  disabled={pagination.page >= pagination.total_pages}
                  onClick={() => loadRequests(pagination.page + 1)}
                  className="p-1.5 border border-slate-200 rounded-lg disabled:opacity-40 hover:bg-slate-50"
                >
                  <ArrowRight className="w-4 h-4" />
                </button>
              </div>
            </div>
          </div>
        </main>
      </div>
    </SidebarLayout>
  );
}
