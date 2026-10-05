'use client';

import React from 'react';
import { getCompletenessColor } from '@/lib/utils';

interface ProgressProps {
  value: number;
  showLabel?: boolean;
  size?: 'sm' | 'md' | 'lg';
}

export function Progress({ value, showLabel = true, size = 'md' }: ProgressProps) {
  const normalized = Math.min(100, Math.max(0, value));
  const color = getCompletenessColor(normalized);

  const height = size === 'sm' ? 'h-1.5' : size === 'lg' ? 'h-3' : 'h-2';

  return (
    <div className="w-full">
      <div className={`w-full overflow-hidden rounded-full bg-slate-200 ${height}`}>
        <div
          className={`h-full transition-all duration-500 ease-out ${color}`}
          style={{ width: `${normalized}%` }}
        />
      </div>
      {showLabel && (
        <div className="mt-1 flex items-center justify-between text-xs text-slate-500 font-medium">
          <span>Completeness</span>
          <span>{normalized}%</span>
        </div>
      )}
    </div>
  );
}
