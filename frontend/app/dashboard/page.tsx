'use client';

import React, { useEffect, useState } from 'react';
import { useAuth } from '@/lib/auth-context';
import { fetchApi } from '@/lib/api';
import { DashboardStats, JobRequest } from '@/types';
import { Sidebar, SidebarLayout } from '@/components/layout/Sidebar';
import { Navbar } from '@/components/layout/Navbar';
import { StatusBadge } from '@/components/ui/StatusBadge';
import Link from 'next/link';
import {
  FileText,
  Clock,
  CheckCircle2,
  AlertTriangle,
  XCircle,
  PlusCircle,
  ArrowUpRight,
  TrendingUp,
} from 'lucide-react';

export default function DashboardPage() {
  const { user, loading: authLoading } = useAuth();
  const [stats, setStats] = useState<DashboardStats | null>(null);
  const [recentRequests, setRecentRequests] = useState<JobRequest[]>([]);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    async function loadDashboard() {
      const res = await fetchApi<{ stats: DashboardStats; recent: JobRequest[] }>(
        '/dashboard/stats.php'
      );

      if (res.success && res.data) {
        setStats(res.data.stats);
        setRecentRequests(res.data.recent || []);
      }
      setLoading(false);
    }

    if (user) {
      loadDashboard();
    }
  }, [user]);

  if (authLoading || (loading && !stats)) {
    return (
      <div className="flex h-screen items-center justify-center bg-slate-900 text-white">
        <div className="animate-spin rounded-full h-8 w-8 border-t-2 border-b-2 border-teal-500" />
      </div>
    );
  }

  return (
    <SidebarLayout>
      <Sidebar />
      <div className="flex-1 flex flex-col min-w-0">
        <Navbar />

        <main className="p-6 space-y-6 max-w-7xl mx-auto w-full">
          {/* Dashboard Header Banner */}
          <div className="bg-gradient-to-r from-slate-900 to-slate-800 text-white p-6 rounded-2xl shadow-sm flex flex-col md:flex-row md:items-center justify-between gap-4">
            <div>
              <h1 className="text-xl font-bold tracking-tight">
                Welcome back, {user?.name || 'User'}!
              </h1>
              <p className="text-xs text-slate-300 mt-1">
                Role: <span className="font-semibold capitalize text-teal-400">{user?.role}</span> • Department: <span className="font-semibold text-slate-200">{user?.department || 'General'}</span>
              </p>
            </div>
            <div className="flex items-center gap-3">
              <Link
                href="/requests/new"
                className="bg-teal-600 hover:bg-teal-500 text-white text-xs font-medium px-4 py-2.5 rounded-xl inline-flex items-center gap-2 shadow-sm transition-all"
              >
                <PlusCircle className="w-4 h-4" />
                <span>New Job Request</span>
              </Link>
            </div>
          </div>

          {/* Metric Stat Cards */}
          <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
            {/* Total Requests Card */}
            <div className="bg-white p-5 rounded-2xl border border-slate-200 shadow-xs flex items-center justify-between">
              <div>
                <p className="text-xs font-medium text-slate-500">Total Requests</p>
                <h3 className="text-2xl font-bold text-slate-900 mt-1">{stats?.total ?? 0}</h3>
              </div>
              <div className="w-11 h-11 rounded-xl bg-slate-100 text-slate-700 flex items-center justify-center">
                <FileText className="w-5 h-5" />
              </div>
            </div>

            {/* Pending Requests Card */}
            <div className="bg-white p-5 rounded-2xl border border-slate-200 shadow-xs flex items-center justify-between">
              <div>
                <p className="text-xs font-medium text-slate-500">Pending Review</p>
                <h3 className="text-2xl font-bold text-amber-600 mt-1">{stats?.pending ?? 0}</h3>
              </div>
              <div className="w-11 h-11 rounded-xl bg-amber-50 text-amber-600 flex items-center justify-center">
                <Clock className="w-5 h-5" />
              </div>
            </div>

            {/* In Progress Card */}
            <div className="bg-white p-5 rounded-2xl border border-slate-200 shadow-xs flex items-center justify-between">
              <div>
                <p className="text-xs font-medium text-slate-500">In Progress</p>
                <h3 className="text-2xl font-bold text-blue-600 mt-1">{stats?.in_progress ?? 0}</h3>
              </div>
              <div className="w-11 h-11 rounded-xl bg-blue-50 text-blue-600 flex items-center justify-center">
                <TrendingUp className="w-5 h-5" />
              </div>
            </div>

            {/* Completed Requests Card */}
            <div className="bg-white p-5 rounded-2xl border border-slate-200 shadow-xs flex items-center justify-between">
              <div>
                <p className="text-xs font-medium text-slate-500">Completed</p>
                <h3 className="text-2xl font-bold text-emerald-600 mt-1">{stats?.completed ?? 0}</h3>
              </div>
              <div className="w-11 h-11 rounded-xl bg-emerald-50 text-emerald-600 flex items-center justify-center">
                <CheckCircle2 className="w-5 h-5" />
              </div>
            </div>
          </div>

          {/* Recent Requests Table */}
          <div className="bg-white rounded-2xl border border-slate-200 shadow-xs overflow-hidden">
            <div className="p-5 border-b border-slate-100 flex items-center justify-between">
              <div>
                <h2 className="text-sm font-bold text-slate-900">Recent Job Requests</h2>
                <p className="text-xs text-slate-500">Latest job request submissions and status</p>
              </div>
              <Link
                href="/requests"
                className="text-xs font-medium text-teal-600 hover:text-teal-700 flex items-center gap-1"
              >
                <span>View All Requests</span>
                <ArrowUpRight className="w-3.5 h-3.5" />
              </Link>
            </div>

            <div className="overflow-x-auto">
              <table className="w-full text-left border-collapse">
                <thead>
                  <tr className="bg-slate-50 border-b border-slate-100 text-[11px] font-semibold text-slate-500 uppercase tracking-wider">
                    <th className="py-3 px-4">Control #</th>
                    <th className="py-3 px-4">Request Type</th>
                    <th className="py-3 px-4">Requester</th>
                    <th className="py-3 px-4">Department</th>
                    <th className="py-3 px-4">Status</th>
                    <th className="py-3 px-4 text-right">Date</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-slate-100 text-xs text-slate-700">
                  {recentRequests.length === 0 ? (
                    <tr>
                      <td colSpan={6} className="py-8 text-center text-slate-400 text-xs">
                        No recent job requests found.
                      </td>
                    </tr>
                  ) : (
                    recentRequests.map((req) => (
                      <tr key={req.id} className="hover:bg-slate-50/80 transition-colors">
                        <td className="py-3 px-4 font-semibold text-slate-900">
                          <Link href={`/requests/${req.id}`} className="hover:text-teal-600">
                            {req.control_number}
                          </Link>
                        </td>
                        <td className="py-3 px-4">{req.request_type}</td>
                        <td className="py-3 px-4 font-medium text-slate-800">{req.requester_name}</td>
                        <td className="py-3 px-4">{req.department}</td>
                        <td className="py-3 px-4">
                          <StatusBadge status={req.status} />
                        </td>
                        <td className="py-3 px-4 text-right text-slate-500">
                          {new Date(req.created_at).toLocaleDateString()}
                        </td>
                      </tr>
                    ))
                  )}
                </tbody>
              </table>
            </div>
          </div>
        </main>
      </div>
    </SidebarLayout>
  );
}
