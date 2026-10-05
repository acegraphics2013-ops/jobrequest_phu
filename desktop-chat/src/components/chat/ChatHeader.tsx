import React from 'react';
import { Lock, Minus, ExternalLink, X, ShieldAlert } from 'lucide-react';
import { PresenceIndicator } from './PresenceIndicator';
import { uiStore } from '../../stores/uiStore';
import { authStore } from '../../stores/authStore';

interface ChatHeaderProps {
  title: string;
  isOnline?: boolean;
  subtitle?: string;
  onLock: () => void;
  onMinimize: () => void;
  onClose: () => void;
}

export const ChatHeader: React.FC<ChatHeaderProps> = ({
  title,
  isOnline = true,
  subtitle,
  onLock,
  onMinimize,
  onClose,
}) => {
  const handleOpenFullWeb = () => {
    window.open('http://localhost/jobrequest_phu/chat_center.php', '_blank');
  };

  return (
    <div className="h-14 bg-slate-900 border-b border-slate-800 px-4 flex items-center justify-between text-slate-100 select-none flex-shrink-0">
      {/* Title & Presence */}
      <div className="flex items-center space-x-2.5 overflow-hidden">
        <div className="relative flex-shrink-0">
          <div className="w-8 h-8 rounded-full bg-blue-600/30 text-blue-400 font-bold flex items-center justify-center text-xs border border-blue-500/30">
            {title.substring(0, 2).toUpperCase()}
          </div>
          <div className="absolute -bottom-0.5 -right-0.5">
            <PresenceIndicator isOnline={isOnline} size="sm" />
          </div>
        </div>

        <div className="min-w-0">
          <h2 className="text-sm font-semibold truncate text-slate-100">{title}</h2>
          <p className="text-[11px] text-slate-400 truncate">
            {subtitle || (isOnline ? 'Online • PHU Staff' : 'Offline')}
          </p>
        </div>
      </div>

      {/* Action Controls */}
      <div className="flex items-center space-x-1">
        <button
          onClick={onLock}
          className="p-1.5 rounded-lg text-slate-400 hover:text-amber-400 hover:bg-slate-800 transition-colors"
          title="Privacy Lock (Auto 2-min Idle)"
        >
          <Lock className="w-4 h-4" />
        </button>

        <button
          onClick={onMinimize}
          className="p-1.5 rounded-lg text-slate-400 hover:text-white hover:bg-slate-800 transition-colors"
          title="Minimize to Floating Chat Head"
        >
          <Minus className="w-4 h-4" />
        </button>

        <button
          onClick={handleOpenFullWeb}
          className="p-1.5 rounded-lg text-slate-400 hover:text-white hover:bg-slate-800 transition-colors"
          title="Open Full PHU Web Chat Center"
        >
          <ExternalLink className="w-4 h-4" />
        </button>

        <button
          onClick={onClose}
          className="p-1.5 rounded-lg text-slate-400 hover:text-red-400 hover:bg-slate-800 transition-colors"
          title="Close Window"
        >
          <X className="w-4 h-4" />
        </button>
      </div>
    </div>
  );
};
