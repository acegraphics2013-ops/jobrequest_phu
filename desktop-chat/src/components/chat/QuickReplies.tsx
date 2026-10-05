import React from 'react';

interface QuickRepliesProps {
  onSelect: (reply: string) => void;
}

export const QuickReplies: React.FC<QuickRepliesProps> = ({ onSelect }) => {
  const replies = ['Got it', 'On it', 'Request Update', 'Discuss further'];

  return (
    <div className="flex items-center space-x-1.5 px-3 py-1.5 bg-slate-900 border-t border-slate-800/60 overflow-x-auto no-scrollbar">
      {replies.map((text) => (
        <button
          key={text}
          type="button"
          onClick={() => onSelect(text)}
          className="px-2.5 py-1 bg-slate-800 hover:bg-blue-600/30 hover:border-blue-500/50 text-slate-300 hover:text-blue-300 text-[11px] font-medium rounded-full border border-slate-700/60 whitespace-nowrap transition-all active:scale-95"
        >
          {text}
        </button>
      ))}
    </div>
  );
};
