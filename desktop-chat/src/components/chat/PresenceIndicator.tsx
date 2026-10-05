import React from 'react';

interface PresenceIndicatorProps {
  isOnline: boolean;
  size?: 'sm' | 'md';
}

export const PresenceIndicator: React.FC<PresenceIndicatorProps> = ({ isOnline, size = 'sm' }) => {
  const dim = size === 'sm' ? 'w-2.5 h-2.5' : 'w-3.5 h-3.5';

  return (
    <span
      className={`${dim} rounded-full border-2 border-white dark:border-slate-900 inline-block ${
        isOnline ? 'bg-emerald-500 shadow-emerald-500/50' : 'bg-slate-400'
      }`}
      title={isOnline ? 'Online' : 'Offline'}
    />
  );
};
