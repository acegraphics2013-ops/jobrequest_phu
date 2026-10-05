import React, { useState, useRef } from 'react';
import { MessageCircle, Lock } from 'lucide-react';
import { UnreadBadge } from './UnreadBadge';
import { uiStore } from '../../stores/uiStore';

interface ChatHeadProps {
  unreadCount: number;
  isLocked?: boolean;
  onClick: () => void;
}

export const ChatHead: React.FC<ChatHeadProps> = ({ unreadCount, isLocked = false, onClick }) => {
  const [isHovered, setIsHovered] = useState(false);
  const dragStartRef = useRef<{ x: number; y: number }>({ x: 0, y: 0 });
  const isDraggingRef = useRef(false);

  const handlePointerDown = (e: React.PointerEvent) => {
    dragStartRef.current = { x: e.clientX, y: e.clientY };
    isDraggingRef.current = false;
  };

  const handlePointerMove = (e: React.PointerEvent) => {
    const dx = Math.abs(e.clientX - dragStartRef.current.x);
    const dy = Math.abs(e.clientY - dragStartRef.current.y);
    if (dx > 5 || dy > 5) {
      isDraggingRef.current = true;
    }
  };

  const handlePointerUp = () => {
    if (!isDraggingRef.current) {
      onClick();
    }
  };

  return (
    <div
      onPointerDown={handlePointerDown}
      onPointerMove={handlePointerMove}
      onPointerUp={handlePointerUp}
      onMouseEnter={() => setIsHovered(true)}
      onMouseLeave={() => setIsHovered(false)}
      className="relative w-14 h-14 rounded-full bg-gradient-to-tr from-blue-700 via-blue-600 to-indigo-500 text-white flex items-center justify-center shadow-xl shadow-blue-600/40 cursor-grab active:cursor-grabbing hover:scale-105 active:scale-95 transition-transform duration-150 select-none group"
      title="PHU Workbench Desktop Chat"
    >
      <MessageCircle className="w-7 h-7 stroke-[2.2]" />

      {/* Top Right Unread Count Badge */}
      {unreadCount > 0 && (
        <div className="absolute -top-1 -right-1">
          <UnreadBadge count={unreadCount} />
        </div>
      )}

      {/* Bottom Right Privacy Lock Indicator */}
      {isLocked && (
        <div className="absolute -bottom-0.5 -right-0.5 w-5 h-5 bg-amber-500 rounded-full border-2 border-slate-900 flex items-center justify-center shadow-md" title="Chat Locked for Privacy">
          <Lock className="w-2.5 h-2.5 text-slate-950 font-bold" />
        </div>
      )}
    </div>
  );
};
