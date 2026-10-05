import React from 'react';

interface UnreadBadgeProps {
  count: number;
  className?: string;
}

export const UnreadBadge: React.FC<UnreadBadgeProps> = ({ count, className = '' }) => {
  if (count <= 0) return null;

  const displayCount = count > 99 ? '99+' : count;

  return (
    <span
      className={`bg-red-500 text-white text-[11px] font-bold px-1.5 py-0.5 rounded-full min-w-[20px] h-[20px] flex items-center justify-center border-2 border-slate-900 shadow-md ${className}`}
    >
      {displayCount}
    </span>
  );
};
