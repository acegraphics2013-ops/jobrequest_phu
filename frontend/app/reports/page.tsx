'use client';

import React, { useEffect, useState } from 'react';
import { useAuth } from '@/lib/auth-context';
import { fetchApi } from '@/lib/api';
import { Sidebar } from '@/components/layout/Sidebar';
import { Navbar } from '@/components/layout/Navbar';
import { Pagination } from '@/types';
import Link from 'next/link';
import { BarChart3, Activity, User, Clock, ArrowLeft, ArrowRight, FileText } from 'lucide-react';

interface ActivityLog {
  id: number;
  request_id: number;
  control_number: string;
  project_title: string;
  user_id: number;
  user_name: string;
  action_type: string;
  action_title: string;
  description: string;
  old_status: string;
  new_status: string;
  created_at: string;
}

export default function ReportsPage() {
  const { user } = useAuth();
  const [logs, setLogs] = useState<ActivityLog[]>([]);
  const [pagination, setPagination] = useState<Pagination>({ total: 0, page: 1, limit: 15, total_pages: 1 });
  const [loading, setLoading] = useState(true);

  const loadLogs = async (pageNum = 1) => {
    setLoading(true);
    const res = await fetchApi<{ logs: ActivityLog[]; pagination: Pagination }>(
      `/activity_logs/index.php?page=${pageNum}&limit=15`
    );
    if (res.success && res.data) {
      setLogs(res.data.logs || []);
      setPagination(res.data.pagination || { total: 0, page: 1, limit: 15, total_pages: 1 });
    }
    setLoading(false);
  };

  useEffect(() => {
    if (user) {
      loadLogs(1);
    }
  }, [user]);

  return (
    <div className="flex min-h-screen bg-slate-50">
      <Sidebar />
      <div className="flex-1 flex flex-col min-w-0">
        <Navbar />

        <main className="p-6 space-y-6 max-w-7xl mx-auto w-full">
          <div>
            <h1 className="text-xl font-bold text-slate-900 tracking-tight">Reports & System Audit Trail</h1>
            <p className="text-xs text-slate-500">Activity logs and status transition audit history</p>
          </div>

          {/* Audit Logs Table Card */}
          <div className="bg-white rounded-2xl border border-slate-200 shadow-xs overflow-hidden">
            <div className="p-5 border-b border-slate-100 flex items-center justify-between">
              <h2 className="text-sm font-bold text-slate-900 flex items-center gap-2">
                <Activity className="w-4 h-4 text-teal-600" />
                <span>System Activity Logs ({pagination.total})</span>
              </h2>
            </div>

            <div className="overflow-x-auto">
              <table className="w-full text-left border-collapse">
                <thead>
                  <tr className="bg-slate-50 border-b border-slate-100 text-[11px] font-semibold text-slate-500 uppercase tracking-wider">
                    <th className="py-3.5 px-4">Timestamp</th>
                    <th className="py-3.5 px-4">User</th>
                    <th className="py-3.5 px-4">Action</th>
                    <th className="py-3.5 px-4">Target Request</th>
                    <th className="py-3.5 px-4">Status Transition</th>
                    <th className="py-3.5 px-4">Description</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-slate-100 text-xs text-slate-700">
                  {loading ? (
                    <tr>
                      <td colSpan={6} className="py-12 text-center text-slate-400">
                        Loading activity audit logs...
                      </td>
                    </tr>
                  ) : logs.length === 0 ? (
                    <tr>
                      <td colSpan={6} className="py-12 text-center text-slate-400">
                        No activity log entries found.
                      </td>
                    </tr>
                  ) : (
                    logs.map((log) => (
                      <tr key={log.id} className="hover:bg-slate-50/80 transition-colors">
                        <td className="py-3.5 px-4 text-slate-500 font-mono text-[11px]">
                          {new Date(log.created_at).toLocaleString()}
                        </td>
                        <td className="py-3.5 px-4 font-medium text-slate-900">
                          {log.user_name}
                        </td>
                        <td className="py-3.5 px-4">
                          <span className="px-2 py-0.5 rounded text-[10px] font-semibold bg-slate-100 text-slate-700 border border-slate-200 uppercase">
                            {log.action_title}
                          </span>
                        </td>
                        <td className="py-3.5 px-4 font-semibold text-teal-700">
                          {log.request_id > 0 ? (
                            <Link href={`/requests/${log.request_id}`} className="hover:underline">
                              {log.control_number}
                            </Link>
                          ) : (
                            'System'
                          )}
                        </td>
                        <td className="py-3.5 px-4 text-slate-600">
                          {log.old_status && log.new_status ? (
                            <span className="text-[11px] bg-slate-100 px-2 py-0.5 rounded border border-slate-200">
                              {log.old_status} → {log.new_status}
                            </span>
                          ) : (
                            '—'
                          )}
                        </td>
                        <td className="py-3.5 px-4 text-slate-600 max-w-xs truncate">
                          {log.description || 'Action performed.'}
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
                Page {pagination.page} of {pagination.total_pages || 1} ({pagination.total} total log entries)
              </span>
              <div className="flex items-center gap-2">
                <button
                  disabled={pagination.page <= 1}
                  onClick={() => loadLogs(pagination.page - 1)}
                  className="p-1.5 border border-slate-200 rounded-lg disabled:opacity-40 hover:bg-slate-50"
                >
                  <ArrowLeft className="w-4 h-4" />
                </button>
                <button
                  disabled={pagination.page >= pagination.total_pages}
                  onClick={() => loadLogs(pagination.page + 1)}
                  className="p-1.5 border border-slate-200 rounded-lg disabled:opacity-40 hover:bg-slate-50"
                >
                  <ArrowRight className="w-4 h-4" />
                </button>
              </div>
            </div>
          </div>
        </main>
      </div>
    </div>
  );
}
