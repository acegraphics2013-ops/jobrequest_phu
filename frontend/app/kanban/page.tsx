'use client';

import React, { useEffect, useState } from 'react';
import { useAuth } from '@/lib/auth-context';
import { fetchApi } from '@/lib/api';
import { Sidebar } from '@/components/layout/Sidebar';
import { Navbar } from '@/components/layout/Navbar';
import Link from 'next/link';
import { Kanban, ArrowRight, User, Building, Clock, CheckCircle2, ChevronRight } from 'lucide-react';

interface KanbanItem {
  id: number;
  control_number: string;
  project_title: string;
  requester_name: string;
  department: string;
  status: string;
  priority: string;
  assigned_to: string;
  created_at: string;
}

interface KanbanColumn {
  title: string;
  items: KanbanItem[];
}

interface KanbanBoard {
  pending: KanbanColumn;
  in_progress: KanbanColumn;
  under_review: KanbanColumn;
  completed: KanbanColumn;
}

export default function KanbanPage() {
  const { user } = useAuth();
  const [board, setBoard] = useState<KanbanBoard | null>(null);
  const [loading, setLoading] = useState(true);
  const [updatingId, setUpdatingId] = useState<number | null>(null);

  const loadBoard = async () => {
    setLoading(true);
    const res = await fetchApi<{ board: KanbanBoard }>('/kanban/index.php');
    if (res.success && res.data?.board) {
      setBoard(res.data.board);
    }
    setLoading(false);
  };

  useEffect(() => {
    if (user) {
      loadBoard();
    }
  }, [user]);

  const handleMoveStatus = async (requestId: number, newStatus: string) => {
    setUpdatingId(requestId);
    const res = await fetchApi('/kanban/move.php', {
      method: 'POST',
      body: JSON.stringify({ request_id: requestId, status: newStatus }),
    });

    if (res.success) {
      loadBoard();
    }
    setUpdatingId(null);
  };

  const columnsList: { key: keyof KanbanBoard; label: string; headerBg: string; nextStatus?: string; nextLabel?: string }[] = [
    { key: 'pending', label: 'Pending Review', headerBg: 'border-t-4 border-amber-500', nextStatus: 'In Progress', nextLabel: 'Start Progress' },
    { key: 'in_progress', label: 'In Progress', headerBg: 'border-t-4 border-blue-500', nextStatus: 'Under Review', nextLabel: 'Send to Review' },
    { key: 'under_review', label: 'Under Review', headerBg: 'border-t-4 border-teal-500', nextStatus: 'Completed', nextLabel: 'Mark Completed' },
    { key: 'completed', label: 'Completed', headerBg: 'border-t-4 border-emerald-500' },
  ];

  return (
    <div className="flex min-h-screen bg-slate-50">
      <Sidebar />
      <div className="flex-1 flex flex-col min-w-0">
        <Navbar />

        <main className="p-6 space-y-6 max-w-7xl mx-auto w-full">
          <div>
            <h1 className="text-xl font-bold text-slate-900 tracking-tight">Kanban Board</h1>
            <p className="text-xs text-slate-500">Visual workflow progress monitoring for HPU job requests</p>
          </div>

          {/* Columns Grid */}
          <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4 items-start">
            {columnsList.map((col) => {
              const columnData = board?.[col.key];
              const items = columnData?.items || [];

              return (
                <div
                  key={col.key}
                  className={`bg-white rounded-2xl border border-slate-200 shadow-xs overflow-hidden ${col.headerBg}`}
                >
                  {/* Column Header */}
                  <div className="p-4 border-b border-slate-100 flex items-center justify-between bg-slate-50/50">
                    <h3 className="font-bold text-xs text-slate-900">{col.label}</h3>
                    <span className="w-5 h-5 rounded-full bg-slate-200 text-slate-700 font-semibold text-[11px] flex items-center justify-center">
                      {items.length}
                    </span>
                  </div>

                  {/* Column Content */}
                  <div className="p-3 space-y-3 min-h-[450px] max-h-[650px] overflow-y-auto">
                    {loading ? (
                      <div className="p-4 text-center text-xs text-slate-400">Loading items...</div>
                    ) : items.length === 0 ? (
                      <div className="p-8 text-center text-xs text-slate-400 italic">No requests in this column</div>
                    ) : (
                      items.map((item) => (
                        <div
                          key={item.id}
                          className="bg-white p-4 rounded-xl border border-slate-200 shadow-2xs space-y-3 hover:border-teal-500 transition-colors"
                        >
                          <div className="flex items-center justify-between">
                            <Link
                              href={`/requests/${item.id}`}
                              className="font-bold text-xs text-slate-900 hover:text-teal-600"
                            >
                              {item.control_number}
                            </Link>
                            <span
                              className={`px-2 py-0.5 rounded text-[10px] font-semibold ${
                                item.priority === 'Urgent'
                                  ? 'bg-rose-100 text-rose-700'
                                  : item.priority === 'High'
                                  ? 'bg-amber-100 text-amber-700'
                                  : 'bg-slate-100 text-slate-600'
                              }`}
                            >
                              {item.priority}
                            </span>
                          </div>

                          <div>
                            <p className="text-xs font-semibold text-slate-800 line-clamp-1">{item.project_title}</p>
                            <p className="text-[11px] text-slate-500 flex items-center gap-1 mt-1">
                              <Building className="w-3 h-3 text-slate-400" />
                              <span>{item.department}</span>
                            </p>
                          </div>

                          <div className="pt-2 border-t border-slate-100 flex items-center justify-between text-[11px] text-slate-400">
                            <span>{new Date(item.created_at).toLocaleDateString()}</span>

                            {/* Move Button */}
                            {col.nextStatus && (
                              <button
                                disabled={updatingId === item.id}
                                onClick={() => handleMoveStatus(item.id, col.nextStatus!)}
                                className="inline-flex items-center gap-1 text-teal-700 hover:text-teal-800 font-semibold hover:underline text-[11px]"
                              >
                                <span>{col.nextLabel}</span>
                                <ChevronRight className="w-3.5 h-3.5" />
                              </button>
                            )}
                          </div>
                        </div>
                      ))
                    )}
                  </div>
                </div>
              );
            })}
          </div>
        </main>
      </div>
    </div>
  );
}
