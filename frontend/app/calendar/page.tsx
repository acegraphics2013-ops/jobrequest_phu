'use client';

import React, { useEffect, useState } from 'react';
import { useAuth } from '@/lib/auth-context';
import { fetchApi } from '@/lib/api';
import { Sidebar } from '@/components/layout/Sidebar';
import { Navbar } from '@/components/layout/Navbar';
import { Calendar as CalendarIcon, MapPin, Tag, Ban, Clock, PlusCircle } from 'lucide-react';

interface CalendarActivity {
  id: number;
  title: string;
  activity_type: string;
  calendar_scope: string;
  location: string;
  description: string;
  start_datetime: string;
  end_datetime: string;
  color: string;
}

interface BlockedDate {
  id: number;
  start_date: string;
  end_date: string;
  service_type: string;
  reason: string;
}

export default function CalendarPage() {
  const { user } = useAuth();
  const [activities, setActivities] = useState<CalendarActivity[]>([]);
  const [blockedDates, setBlockedDates] = useState<BlockedDate[]>([]);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    async function loadCalendar() {
      const res = await fetchApi<{ activities: CalendarActivity[]; blocked_dates: BlockedDate[] }>(
        '/calendar/index.php'
      );
      if (res.success && res.data) {
        setActivities(res.data.activities || []);
        setBlockedDates(res.data.blocked_dates || []);
      }
      setLoading(false);
    }
    if (user) {
      loadCalendar();
    }
  }, [user]);

  return (
    <div className="flex min-h-screen bg-slate-50">
      <Sidebar />
      <div className="flex-1 flex flex-col min-w-0">
        <Navbar />

        <main className="p-6 space-y-6 max-w-7xl mx-auto w-full">
          <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
            <div>
              <h1 className="text-xl font-bold text-slate-900 tracking-tight">Calendar of Activities</h1>
              <p className="text-xs text-slate-500">HPU & Hospital schedule overview and blocked service dates</p>
            </div>
          </div>

          {/* Grid Layout */}
          <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
            {/* Scheduled Activities (2 cols) */}
            <div className="lg:col-span-2 bg-white p-6 rounded-2xl border border-slate-200 shadow-xs space-y-4">
              <div className="flex items-center justify-between border-b border-slate-100 pb-3">
                <h2 className="text-sm font-bold text-slate-900 flex items-center gap-2">
                  <CalendarIcon className="w-4 h-4 text-teal-600" />
                  <span>Scheduled Activities & Events</span>
                </h2>
                <span className="text-xs text-slate-400">{activities.length} Events</span>
              </div>

              {loading ? (
                <div className="py-12 text-center text-xs text-slate-400">Loading activities...</div>
              ) : activities.length === 0 ? (
                <div className="py-12 text-center text-xs text-slate-400">No scheduled activities found.</div>
              ) : (
                <div className="space-y-3">
                  {activities.map((act) => (
                    <div
                      key={act.id}
                      className="p-4 rounded-xl border border-slate-200 bg-slate-50/50 hover:bg-slate-100/70 transition-colors space-y-2"
                    >
                      <div className="flex items-center justify-between">
                        <span className="text-xs font-bold text-slate-900">{act.title}</span>
                        <span
                          className="px-2 py-0.5 rounded text-[10px] font-semibold text-white"
                          style={{ backgroundColor: act.color || '#0f766e' }}
                        >
                          {act.calendar_scope}
                        </span>
                      </div>

                      <div className="flex flex-wrap items-center gap-4 text-xs text-slate-500">
                        <span className="flex items-center gap-1">
                          <Tag className="w-3.5 h-3.5 text-slate-400" />
                          <span>{act.activity_type}</span>
                        </span>
                        {act.location && (
                          <span className="flex items-center gap-1">
                            <MapPin className="w-3.5 h-3.5 text-slate-400" />
                            <span>{act.location}</span>
                          </span>
                        )}
                        <span className="flex items-center gap-1">
                          <Clock className="w-3.5 h-3.5 text-slate-400" />
                          <span>{new Date(act.start_datetime).toLocaleDateString()}</span>
                        </span>
                      </div>
                    </div>
                  ))}
                </div>
              )}
            </div>

            {/* HPU Blocked Dates (1 col) */}
            <div className="bg-white p-6 rounded-2xl border border-slate-200 shadow-xs space-y-4">
              <div className="flex items-center justify-between border-b border-slate-100 pb-3">
                <h2 className="text-sm font-bold text-slate-900 flex items-center gap-2">
                  <Ban className="w-4 h-4 text-rose-600" />
                  <span>HPU Service Blocked Dates</span>
                </h2>
                <span className="text-xs text-slate-400">{blockedDates.length} Ranges</span>
              </div>

              {loading ? (
                <div className="py-12 text-center text-xs text-slate-400">Loading blocked dates...</div>
              ) : blockedDates.length === 0 ? (
                <div className="py-12 text-center text-xs text-slate-400">No blocked dates set.</div>
              ) : (
                <div className="space-y-3">
                  {blockedDates.map((block) => (
                    <div
                      key={block.id}
                      className="p-3.5 rounded-xl border border-rose-200 bg-rose-50/40 text-xs space-y-1"
                    >
                      <div className="flex items-center justify-between font-semibold text-rose-800">
                        <span>{block.service_type}</span>
                        <span className="text-[10px] bg-rose-100 text-rose-700 px-2 py-0.5 rounded font-bold">
                          {block.start_date === block.end_date ? block.start_date : `${block.start_date} - ${block.end_date}`}
                        </span>
                      </div>
                      <p className="text-slate-600 text-[11px]">Reason: {block.reason}</p>
                    </div>
                  ))}
                </div>
              )}
            </div>
          </div>
        </main>
      </div>
    </div>
  );
}
