import React from 'react';
import { User } from '../../types/user';
import { PresenceIndicator } from './PresenceIndicator';
import { UnreadBadge } from './UnreadBadge';

interface ConversationRowProps {
  user?: User;
  channelName?: string;
  channelDesc?: string;
  isChannel?: boolean;
  isActive: boolean;
  unreadCount: number;
  onClick: () => void;
}

export const ConversationRow: React.FC<ConversationRowProps> = ({
  user,
  channelName,
  channelDesc,
  isChannel = false,
  isActive,
  unreadCount,
  onClick,
}) => {
  if (isChannel) {
    return (
      <div
        onClick={onClick}
        className={`px-3 py-2.5 rounded-xl cursor-pointer flex items-center justify-between transition-colors ${
          isActive ? 'bg-blue-600/20 border border-blue-500/30 text-white' : 'hover:bg-slate-800 text-slate-300'
        }`}
      >
        <div className="flex items-center space-x-3 overflow-hidden">
          <div className="w-8 h-8 rounded-lg bg-blue-500/20 text-blue-400 font-bold flex items-center justify-center text-sm flex-shrink-0">
            #
          </div>
          <div className="min-w-0">
            <h4 className="text-xs font-semibold truncate text-slate-100">{channelName}</h4>
            <p className="text-[10px] text-slate-400 truncate">{channelDesc}</p>
          </div>
        </div>
        {unreadCount > 0 && <UnreadBadge count={unreadCount} />}
      </div>
    );
  }

  if (!user) return null;

  const initials = user.name.split(' ').map((n) => n[0]).join('').substring(0, 2).toUpperCase();

  return (
    <div
      onClick={onClick}
      className={`px-3 py-2.5 rounded-xl cursor-pointer flex items-center justify-between transition-colors ${
        isActive ? 'bg-blue-600/20 border border-blue-500/30 text-white' : 'hover:bg-slate-800 text-slate-300'
      }`}
    >
      <div className="flex items-center space-x-3 overflow-hidden">
        <div className="relative flex-shrink-0">
          {user.photo_url ? (
            <img src={user.photo_url} alt={user.name} className="w-8 h-8 rounded-full object-cover" />
          ) : (
            <div className="w-8 h-8 rounded-full bg-slate-700 text-slate-200 font-bold flex items-center justify-center text-xs">
              {initials}
            </div>
          )}
          <div className="absolute -bottom-0.5 -right-0.5">
            <PresenceIndicator isOnline={!!user.is_online} size="sm" />
          </div>
        </div>

        <div className="min-w-0">
          <h4 className="text-xs font-semibold truncate text-slate-100">{user.name}</h4>
          <p className="text-[10px] text-slate-400 truncate">{user.last_message || user.role || 'Staff'}</p>
        </div>
      </div>

      {unreadCount > 0 && <UnreadBadge count={unreadCount} />}
    </div>
  );
};
