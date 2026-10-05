'use client';

import React from 'react';
import { Progress } from '@/components/ui/Progress';
import { CheckCircle2, XCircle } from 'lucide-react';

interface ChecklistItem {
  key: string;
  label: string;
  completed: boolean;
}

interface DocumentationChecklistProps {
  percentage: number;
  checklist: ChecklistItem[];
}

export function DocumentationChecklist({ percentage, checklist }: DocumentationChecklistProps) {
  return (
    <div className="rounded-xl border border-slate-200 bg-white p-5 shadow-xs">
      <div className="flex items-center justify-between border-b border-slate-100 pb-3 mb-4">
        <h3 className="text-base font-bold text-slate-900">Documentation Completeness</h3>
        <span className="text-sm font-extrabold text-blue-600">{percentage}%</span>
      </div>

      <div className="mb-5">
        <Progress value={percentage} showLabel={false} size="lg" />
      </div>

      <div className="space-y-2.5">
        {checklist.map((item) => (
          <div
            key={item.key}
            className={`flex items-center justify-between p-2.5 rounded-lg border text-xs font-semibold ${
              item.completed
                ? 'bg-emerald-50/60 border-emerald-200 text-emerald-900'
                : 'bg-slate-50 border-slate-200 text-slate-500'
            }`}
          >
            <span className="flex items-center gap-2">
              {item.completed ? (
                <CheckCircle2 className="h-4 w-4 text-emerald-600 shrink-0" />
              ) : (
                <XCircle className="h-4 w-4 text-slate-400 shrink-0" />
              )}
              {item.label}
            </span>
            <span className={item.completed ? 'text-emerald-700' : 'text-slate-400'}>
              {item.completed ? 'Complete ✓' : 'Incomplete ✕'}
            </span>
          </div>
        ))}
      </div>
    </div>
  );
}
