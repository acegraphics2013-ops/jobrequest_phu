'use client';

import React, { useEffect, useState } from 'react';
import Link from 'next/link';
import { fetchApi } from '@/lib/api';
import { StatCard } from '@/components/ui/StatCard';
import { StatusBadge } from '@/components/ui/StatusBadge';
import { CardSkeleton } from '@/components/ui/Skeleton';
import { formatDate } from '@/lib/utils';
import {
  MonthlyActivitiesChart,
  ParticipantsTrendChart,
  ActivityTypesPieChart,
  HorizontalBarChart,
} from '@/components/charts/OverviewCharts';
import {
  ClipboardList,
  Calendar,
  Users,
  MapPin,
  Clock,
  CheckCircle2,
  Send,
  FileCheck,
  Images,
  BookOpen,
  Plus,
  ArrowRight,
  TrendingUp,
} from 'lucide-react';

export default function HealthEducationOverviewPage() {
  const [loading, setLoading] = useState(true);
  const [data, setData] = useState<any>(null);

  const loadData = async () => {
    setLoading(true);
    const res = await fetchApi('/health-education/overview.php');
    if (res.success && res.data) {
      setData(res.data);
    }
    setLoading(false);
  };

  useEffect(() => {
    loadData();
  }, []);

  if (loading) {
    return (
      <div className="space-y-6">
        <div className="flex items-center justify-between">
          <div className="h-6 w-48 bg-slate-200 animate-pulse rounded" />
          <div className="h-9 w-32 bg-slate-200 animate-pulse rounded-lg" />
        </div>
        <div className="grid grid-cols-2 gap-4 md:grid-cols-5">
          {Array.from({ length: 10 }).map((_, i) => (
            <CardSkeleton key={i} />
          ))}
        </div>
      </div>
    );
  }

  const kpis = data?.kpis || {};
  const charts = data?.charts || {};
  const recentActivities = data?.recentActivities || [];

  return (
    <div className="space-y-6">
      {/* Top Banner Header */}
      <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4 border-b border-slate-200 pb-4">
        <div>
          <h1 className="text-2xl font-bold tracking-tight text-slate-900">
            Health Education & Promotion Overview
          </h1>
          <p className="text-sm text-slate-500">
            Real-time analytics, participant engagement stats, ward coverage, and publication monitoring.
          </p>
        </div>

        <div className="flex items-center gap-3">
          <Link
            href="/health-education/new"
            className="inline-flex items-center gap-2 rounded-lg bg-blue-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-blue-700 transition-colors"
          >
            <Plus className="h-4 w-4" />
            New Activity
          </Link>
        </div>
      </div>

      {/* 10 KPI Summary Cards Grid */}
      <div className="grid grid-cols-2 gap-3.5 sm:grid-cols-3 md:grid-cols-5">
        <StatCard
          title="Total Activities"
          value={kpis.total_activities || 0}
          subtitle="All conducted sessions"
          icon={ClipboardList}
          iconColor="text-blue-600"
          iconBg="bg-blue-50"
        />
        <StatCard
          title="This Month"
          value={kpis.activities_this_month || 0}
          subtitle="Current month sessions"
          icon={Calendar}
          iconColor="text-indigo-600"
          iconBg="bg-indigo-50"
        />
        <StatCard
          title="Participants Reached"
          value={kpis.participants_reached || 0}
          subtitle="Patients, family, staff"
          icon={Users}
          iconColor="text-emerald-600"
          iconBg="bg-emerald-50"
        />
        <StatCard
          title="Wards Covered"
          value={kpis.wards_covered || 0}
          subtitle="Hospital areas"
          icon={MapPin}
          iconColor="text-sky-600"
          iconBg="bg-sky-50"
        />
        <StatCard
          title="Pending Doc"
          value={kpis.pending_documentation || 0}
          subtitle="<100% complete"
          icon={FileCheck}
          iconColor="text-amber-600"
          iconBg="bg-amber-50"
        />
        <StatCard
          title="Pending Review"
          value={kpis.pending_review || 0}
          subtitle="Awaiting staff review"
          icon={Clock}
          iconColor="text-orange-600"
          iconBg="bg-orange-50"
        />
        <StatCard
          title="Approved"
          value={kpis.approved || 0}
          subtitle="Supervisor verified"
          icon={CheckCircle2}
          iconColor="text-teal-600"
          iconBg="bg-teal-50"
        />
        <StatCard
          title="Published"
          value={kpis.published || 0}
          subtitle="FB & Web published"
          icon={Send}
          iconColor="text-purple-600"
          iconBg="bg-purple-50"
        />
        <StatCard
          title="Visual Outputs"
          value={kpis.visual_outputs || 0}
          subtitle="Photos & videos"
          icon={Images}
          iconColor="text-rose-600"
          iconBg="bg-rose-50"
        />
        <StatCard
          title="IEC Distributed"
          value={kpis.iec_distributed || 0}
          subtitle="Pamphlets & flyers"
          icon={BookOpen}
          iconColor="text-cyan-600"
          iconBg="bg-cyan-50"
        />
      </div>

      {/* Charts Section Grid */}
      <div className="grid grid-cols-1 gap-6 lg:grid-cols-2">
        {/* Monthly Activity Volume */}
        <div className="rounded-xl border border-slate-200 bg-white p-5 shadow-xs">
          <div className="flex items-center justify-between border-b border-slate-100 pb-3 mb-4">
            <div>
              <h3 className="text-base font-bold text-slate-900">Monthly Activities Trend</h3>
              <p className="text-xs text-slate-500">Activity frequency over past 12 months</p>
            </div>
            <TrendingUp className="h-5 w-5 text-blue-600" />
          </div>
          <MonthlyActivitiesChart data={charts.monthlyTrend} />
        </div>

        {/* Participants Reached Trend */}
        <div className="rounded-xl border border-slate-200 bg-white p-5 shadow-xs">
          <div className="flex items-center justify-between border-b border-slate-100 pb-3 mb-4">
            <div>
              <h3 className="text-base font-bold text-slate-900">Participants Reached Trend</h3>
              <p className="text-xs text-slate-500">Monthly audience reach</p>
            </div>
            <Users className="h-5 w-5 text-emerald-600" />
          </div>
          <ParticipantsTrendChart data={charts.monthlyTrend} />
        </div>

        {/* Activities by Ward */}
        <div className="rounded-xl border border-slate-200 bg-white p-5 shadow-xs">
          <div className="border-b border-slate-100 pb-3 mb-4">
            <h3 className="text-base font-bold text-slate-900">Ward & Area Coverage</h3>
            <p className="text-xs text-slate-500">Top hospital wards by number of activities</p>
          </div>
          <HorizontalBarChart data={charts.byWard} nameKey="ward" />
        </div>

        {/* Activity Types Breakdown */}
        <div className="rounded-xl border border-slate-200 bg-white p-5 shadow-xs">
          <div className="border-b border-slate-100 pb-3 mb-4">
            <h3 className="text-base font-bold text-slate-900">Activity Types Distribution</h3>
            <p className="text-xs text-slate-500">Classes vs lectures vs campaigns</p>
          </div>
          <ActivityTypesPieChart data={charts.byType} />
        </div>
      </div>

      {/* Recent Activities Section */}
      <div className="rounded-xl border border-slate-200 bg-white p-5 shadow-xs">
        <div className="flex items-center justify-between border-b border-slate-100 pb-4 mb-4">
          <div>
            <h3 className="text-base font-bold text-slate-900">Recent Health Education Activities</h3>
            <p className="text-xs text-slate-500">Latest recorded health education sessions and advocacy activities</p>
          </div>
          <Link
            href="/health-education/activities"
            className="inline-flex items-center gap-1 text-xs font-semibold text-blue-600 hover:text-blue-700"
          >
            View All Activities
            <ArrowRight className="h-3.5 w-3.5" />
          </Link>
        </div>

        <div className="divide-y divide-slate-100">
          {recentActivities.length === 0 ? (
            <p className="py-6 text-center text-xs text-slate-400">No activities recorded yet.</p>
          ) : (
            recentActivities.map((act: any) => (
              <div
                key={act.id}
                className="flex flex-col sm:flex-row sm:items-center justify-between gap-3 py-3 hover:bg-slate-50/60 px-2 rounded-lg transition-colors"
              >
                <div className="space-y-1 max-w-xl">
                  <div className="flex items-center gap-2 flex-wrap">
                    <span className="text-xs font-bold text-slate-400">#{act.id}</span>
                    <span className="text-xs font-semibold text-slate-500">• {formatDate(act.session_date)}</span>
                    <span className="rounded bg-slate-100 px-2 py-0.5 text-[10px] font-semibold text-slate-600">
                      {act.ward_area}
                    </span>
                  </div>
                  <Link
                    href={`/health-education/activities/${act.id}`}
                    className="block text-sm font-bold text-slate-900 hover:text-blue-600 line-clamp-1"
                  >
                    {act.title}
                  </Link>
                  <p className="text-xs text-slate-500">
                    Topic: <span className="font-medium text-slate-700">{act.health_topic}</span> • Facilitator: {act.facilitator}
                  </p>
                </div>

                <div className="flex items-center gap-4 sm:justify-end">
                  <div className="text-right hidden sm:block">
                    <p className="text-xs font-bold text-slate-900">{act.participant_count} Participants</p>
                    <p className="text-[10px] text-slate-500">{act.documentation_percentage}% Doc Complete</p>
                  </div>
                  <StatusBadge status={act.status} size="sm" />
                </div>
              </div>
            ))
          )}
        </div>
      </div>
    </div>
  );
}
