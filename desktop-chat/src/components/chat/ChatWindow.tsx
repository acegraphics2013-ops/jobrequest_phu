import React, { useState } from 'react';
import { ChatHeader } from './ChatHeader';
import { ConversationList } from './ConversationList';
import { MessageList } from './MessageList';
import { QuickReplies } from './QuickReplies';
import { AttachmentUploader } from './AttachmentUploader';
import { MessageComposer } from './MessageComposer';
import { ActiveConversation, Channel } from '../../types/conversation';
import { Message } from '../../types/message';
import { User } from '../../types/user';
import { Lock, AlertCircle, Clock } from 'lucide-react';
import { loginApi } from '../../services/auth';

interface ChatWindowProps {
  currentUser: User;
  channels: Channel[];
  teamMembers: User[];
  unreadCounts: Record<number, number>;
  activeConversation: ActiveConversation;
  messages: Message[];
  isLoadingMessages: boolean;
  isSending: boolean;
  isLocked: boolean;
  showIdleWarning: boolean;
  onSelectConversation: (conv: ActiveConversation) => void;
  onSendMessage: (text: string, file?: File | null) => void;
  onLock: () => void;
  onMinimize: () => void;
  onClose: () => void;
  onUnlockSuccess: () => void;
}

export const ChatWindow: React.FC<ChatWindowProps> = ({
  currentUser,
  channels,
  teamMembers,
  unreadCounts,
  activeConversation,
  messages,
  isLoadingMessages,
  isSending,
  isLocked,
  showIdleWarning,
  onSelectConversation,
  onSendMessage,
  onLock,
  onMinimize,
  onClose,
  onUnlockSuccess,
}) => {
  const [selectedFile, setSelectedFile] = useState<File | null>(null);
  const [unlockPassword, setUnlockPassword] = useState('');
  const [unlockError, setUnlockError] = useState<string | null>(null);
  const [isUnlocking, setIsUnlocking] = useState(false);

  const activeUser = teamMembers.find((m) => m.id === activeConversation.receiver_id);
  const title = activeConversation.name || (activeUser ? activeUser.name : 'PHU Chat');
  const isOnline = activeUser ? !!activeUser.is_online : true;

  const handleSend = (text: string, file?: File | null) => {
    onSendMessage(text, file);
    setSelectedFile(null);
  };

  const handleUnlockSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!unlockPassword) {
      setUnlockError('Please enter your account password.');
      return;
    }

    setIsUnlocking(true);
    setUnlockError(null);

    const res = await loginApi(currentUser.email, unlockPassword);
    setIsUnlocking(false);

    if (res.success) {
      setUnlockPassword('');
      setUnlockError(null);
      onUnlockSuccess();
    } else {
      setUnlockError(res.message || 'Incorrect password. Please try again.');
    }
  };

  return (
    <div className="w-full h-full bg-slate-900 border border-slate-700/80 rounded-2xl shadow-2xl overflow-hidden flex flex-col select-none relative">
      {/* Header Bar */}
      <ChatHeader
        title={title}
        isOnline={isOnline}
        onLock={onLock}
        onMinimize={onMinimize}
        onClose={onClose}
      />

      {/* Main Split Layout */}
      <div className="flex-1 flex overflow-hidden relative">
        {/* Left Conversations Sidebar */}
        <div className="w-56 flex-shrink-0">
          <ConversationList
            channels={channels}
            teamMembers={teamMembers}
            unreadCounts={unreadCounts}
            activeConversation={activeConversation}
            onSelectConversation={onSelectConversation}
          />
        </div>

        {/* Right Active Conversation Panel */}
        <div className="flex-1 flex flex-col bg-slate-950 overflow-hidden relative">
          {/* Idle 15s Warning Banner */}
          {showIdleWarning && (
            <div className="bg-amber-500/20 border-b border-amber-500/30 px-3 py-1.5 flex items-center space-x-2 text-amber-300 text-xs animate-pulse">
              <Clock className="w-3.5 h-3.5 flex-shrink-0" />
              <span>Chat will lock in 15 seconds due to inactivity.</span>
            </div>
          )}

          {/* Messages Feed */}
          <MessageList
            messages={messages}
            currentUserId={currentUser.id}
            isLoading={isLoadingMessages}
          />

          {/* Quick Replies Chips */}
          <QuickReplies onSelect={(reply) => handleSend(reply)} />

          {/* Attachment Bar */}
          <AttachmentUploader file={selectedFile} onCancel={() => setSelectedFile(null)} />

          {/* Composer Form */}
          <MessageComposer
            onSend={handleSend}
            selectedFile={selectedFile}
            onFileSelect={(file) => setSelectedFile(file)}
            isSending={isSending}
          />
        </div>
      </div>

      {/* 2-Minute Privacy Locked Screen Overlay */}
      {isLocked && (
        <div className="absolute inset-0 bg-slate-950/95 backdrop-blur-md z-50 flex flex-col items-center justify-center p-6 text-center">
          <div className="w-16 h-16 rounded-full bg-amber-500/10 border border-amber-500/30 text-amber-400 flex items-center justify-center mb-4">
            <Lock className="w-8 h-8" />
          </div>

          <h2 className="text-lg font-bold text-slate-100 mb-1">PHU Chat Privacy Locked</h2>
          <p className="text-xs text-slate-400 mb-6 max-w-xs">
            Enter account password for <strong className="text-slate-200">{currentUser.name}</strong> to resume chat.
          </p>

          <form onSubmit={handleUnlockSubmit} className="w-full max-w-xs space-y-3">
            <input
              type="password"
              value={unlockPassword}
              onChange={(e) => setUnlockPassword(e.target.value)}
              placeholder="Account password..."
              autoFocus
              className="w-full px-3 py-2 bg-slate-900 border border-slate-700 rounded-xl text-sm text-slate-100 placeholder-slate-500 focus:outline-none focus:ring-2 focus:ring-amber-500"
            />

            {unlockError && (
              <div className="p-2 bg-red-500/10 border border-red-500/30 text-red-400 text-xs rounded-xl flex items-center space-x-1.5 justify-center">
                <AlertCircle className="w-3.5 h-3.5 flex-shrink-0" />
                <span>{unlockError}</span>
              </div>
            )}

            <button
              type="submit"
              disabled={isUnlocking}
              className="w-full py-2 bg-amber-500 hover:bg-amber-400 text-slate-950 font-bold rounded-xl text-sm transition-all shadow-md shadow-amber-500/20 active:scale-95 disabled:opacity-50"
            >
              {isUnlocking ? 'Verifying Password...' : 'Unlock Chat'}
            </button>
          </form>
        </div>
      )}
    </div>
  );
};
