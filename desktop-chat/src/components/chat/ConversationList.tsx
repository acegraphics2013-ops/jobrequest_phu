import React, { useState } from 'react';
import { Search, Hash, Users } from 'lucide-react';
import { ConversationRow } from './ConversationRow';
import { Channel, ActiveConversation } from '../../types/conversation';
import { User } from '../../types/user';

interface ConversationListProps {
  channels: Channel[];
  teamMembers: User[];
  unreadCounts: Record<number, number>;
  activeConversation: ActiveConversation;
  onSelectConversation: (conv: ActiveConversation) => void;
}

export const ConversationList: React.FC<ConversationListProps> = ({
  channels,
  teamMembers,
  unreadCounts,
  activeConversation,
  onSelectConversation,
}) => {
  const [searchQuery, setSearchQuery] = useState('');

  const filteredMembers = teamMembers.filter((m) =>
    m.name.toLowerCase().includes(searchQuery.toLowerCase()) ||
    (m.department || '').toLowerCase().includes(searchQuery.toLowerCase())
  );

  return (
    <div className="w-full h-full flex flex-col bg-slate-900/90 border-r border-slate-800 text-slate-100 select-none overflow-hidden">
      {/* Search Input Box */}
      <div className="p-3 border-b border-slate-800">
        <div className="relative">
          <Search className="w-4 h-4 absolute left-3 top-2.5 text-slate-400" />
          <input
            type="text"
            value={searchQuery}
            onChange={(e) => setSearchQuery(e.target.value)}
            placeholder="Search team & channels..."
            className="w-full pl-9 pr-3 py-1.5 bg-slate-800 border border-slate-700/80 rounded-xl text-xs text-slate-100 placeholder-slate-400 focus:outline-none focus:ring-1 focus:ring-blue-500"
          />
        </div>
      </div>

      {/* List Container */}
      <div className="flex-1 overflow-y-auto p-2 space-y-4">
        {/* Team Channels Section */}
        <div>
          <div className="px-2 mb-1.5 flex items-center space-x-1.5 text-[10px] font-bold text-slate-400 uppercase tracking-wider">
            <Hash className="w-3 h-3" />
            <span>Team Channels</span>
          </div>
          <div className="space-y-0.5">
            {channels.map((ch) => (
              <ConversationRow
                key={ch.key}
                isChannel
                channelName={ch.name}
                channelDesc={ch.desc}
                isActive={!activeConversation.receiver_id && activeConversation.channel === ch.key}
                unreadCount={ch.unread || 0}
                onClick={() => onSelectConversation({ channel: ch.key, receiver_id: null, name: ch.name })}
              />
            ))}
          </div>
        </div>

        {/* Direct Messages Section */}
        <div>
          <div className="px-2 mb-1.5 flex items-center space-x-1.5 text-[10px] font-bold text-slate-400 uppercase tracking-wider">
            <Users className="w-3 h-3" />
            <span>Direct Messages</span>
          </div>
          <div className="space-y-0.5">
            {filteredMembers.length > 0 ? (
              filteredMembers.map((member) => (
                <ConversationRow
                  key={member.id}
                  user={member}
                  isActive={activeConversation.receiver_id === member.id}
                  unreadCount={unreadCounts[member.id] || 0}
                  onClick={() => onSelectConversation({ channel: null, receiver_id: member.id, name: member.name })}
                />
              ))
            ) : (
              <div className="px-3 py-4 text-center text-xs text-slate-500">No team members found</div>
            )}
          </div>
        </div>
      </div>
    </div>
  );
};
