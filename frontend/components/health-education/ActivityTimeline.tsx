'use client';

import React from 'react';
import { formatDateTime } from '@/lib/utils';
import { Clock, User, CheckCircle, FileText, Send, Archive, RefreshCw } from 'lucide-react';

interface TimelineItem {
  id: number;
  user_name: string;
  action: string;
  description: string;
  created_at: string;
}

interface ActivityTimelineProps {
  timeline: TimelineItem[];
}

export function ActivityTimeline({ timeline }: ActivityTimelineProps) {
  if (!timeline || timeline.length === 0) {
    return <p className="text-xs text-slate-400 italic">No activity history recorded yet.</p>;
  }

  const getActionIcon = (action: string) => {
    switch (action) {
      case 'Created':
        return <FileText className="h-3.5 w-3.5 text-blue-600" />;
      case 'Status Changed':
      case 'Approved':
      case 'Published':
        return <CheckCircle className="h-3.5 w-3.5 text-emerald-600" />;
      case 'Sent to Publication':
        return <Send className="h-3.5 w-3.5 text-purple-600" />;
      case 'Edited':
        return <RefreshCw className="h-3.5 w-3.5 text-amber-600" />;
      default:
        return <Clock className="h-3.5 w-3.5 text-slate-500" />;
    }
  };

  return (
    <div className="rounded-xl border border-slate-200 bg-white p-5 shadow-xs">
      <h3 className="text-base font-bold text-slate-900 border-b border-slate-100 pb-3 mb-4">
        Activity Audit Timeline
      </h3>

      <div className="relative border-l-2 border-slate-200 ml-3 pl-5 space-y-6">
        {timeline.map((item) => (
          <div key={item.id} className="relative">
            {/* Timeline Dot */}
            <div className="absolute -left-[27px] top-0 flex h-6 w-6 items-center justify-center rounded-full bg-slate-100 border-2 border-white shadow-xs">
              {getActionIcon(item.action)}
            </div>

            <div>
              <p className="text-xs font-bold text-slate-900">{item.action}</p>
              <p className="text-xs text-slate-600 mt-0.5">{item.description}</p>
              <p className="text-[10px] font-medium text-slate-400 mt-1 flex items-center gap-1">
                <Clock className="h-3 w-3" />
                {formatDateTime(item.created_at)}
              </p>
            </div>
          </div>
        ))}
      </div>
    </div>
  );
}
