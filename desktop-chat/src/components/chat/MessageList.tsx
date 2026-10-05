import React, { useEffect, useRef, useState } from 'react';
import { Message } from '../../types/message';
import { MessageBubble } from './MessageBubble';
import { ChevronDown } from 'lucide-react';

interface MessageListProps {
  messages: Message[];
  currentUserId: number;
  isLoading: boolean;
}

export const MessageList: React.FC<MessageListProps> = ({
  messages,
  currentUserId,
  isLoading,
}) => {
  const containerRef = useRef<HTMLDivElement>(null);
  const [showScrollBtn, setShowScrollBtn] = useState(false);

  const scrollToBottom = (behavior: ScrollBehavior = 'smooth') => {
    if (containerRef.current) {
      containerRef.current.scrollTo({
        top: containerRef.current.scrollHeight,
        behavior,
      });
    }
  };

  useEffect(() => {
    scrollToBottom('auto');
  }, [messages.length]);

  const handleScroll = () => {
    if (!containerRef.current) return;
    const { scrollTop, scrollHeight, clientHeight } = containerRef.current;
    const isBottom = scrollHeight - scrollTop - clientHeight < 80;
    setShowScrollBtn(!isBottom);
  };

  return (
    <div className="relative flex-1 bg-slate-950 overflow-hidden">
      <div
        ref={containerRef}
        onScroll={handleScroll}
        className="w-full h-full overflow-y-auto p-4 space-y-1"
      >
        {isLoading && messages.length === 0 ? (
          <div className="flex justify-center items-center h-full text-xs text-slate-500">
            Loading chat history...
          </div>
        ) : messages.length === 0 ? (
          <div className="flex flex-col justify-center items-center h-full text-xs text-slate-500 space-y-1">
            <p className="font-semibold text-slate-400">No messages yet</p>
            <p className="text-[11px]">Say hello to start the conversation!</p>
          </div>
        ) : (
          messages.map((msg, idx) => {
            const isMe = msg.sender_id === currentUserId;
            const prevMsg = idx > 0 ? messages[idx - 1] : null;
            const showAvatar = !prevMsg || prevMsg.sender_id !== msg.sender_id;

            return (
              <MessageBubble
                key={msg.id}
                message={msg}
                isMe={isMe}
                showAvatar={showAvatar}
                showName={showAvatar}
              />
            );
          })
        )}
      </div>

      {/* Floating Scroll Down Chip */}
      {showScrollBtn && (
        <button
          onClick={() => scrollToBottom('smooth')}
          className="absolute bottom-3 right-4 bg-blue-600 hover:bg-blue-500 text-white text-xs font-semibold px-3 py-1.5 rounded-full shadow-lg flex items-center space-x-1 border border-blue-400/40 transition-all active:scale-95"
        >
          <span>New messages</span>
          <ChevronDown className="w-3.5 h-3.5" />
        </button>
      )}
    </div>
  );
};
