import React from 'react';
import { Message } from '../../types/message';
import { FileText, Image as ImageIcon, Download } from 'lucide-react';

interface MessageBubbleProps {
  message: Message;
  isMe: boolean;
  showAvatar?: boolean;
  showName?: boolean;
}

export const MessageBubble: React.FC<MessageBubbleProps> = ({
  message,
  isMe,
  showAvatar = true,
  showName = true,
}) => {
  const initials = message.sender_name.split(' ').map((n) => n[0]).join('').substring(0, 2).toUpperCase();

  const formattedTime = new Date(message.created_at).toLocaleTimeString([], {
    hour: '2-digit',
    minute: '2-digit',
  });

  return (
    <div className={`flex items-end space-x-2 my-1 ${isMe ? 'flex-row-reverse space-x-reverse' : 'flex-row'}`}>
      {/* Sender Avatar */}
      {!isMe && (
        <div className="w-7 h-7 rounded-full bg-slate-700 text-slate-200 font-bold flex items-center justify-center text-[10px] flex-shrink-0 mb-1">
          {showAvatar ? initials : ''}
        </div>
      )}

      {/* Bubble Box */}
      <div className={`max-w-[75%] rounded-2xl px-3.5 py-2 text-xs shadow-sm ${
        isMe
          ? 'bg-gradient-to-tr from-blue-700 to-blue-600 text-white rounded-br-xs'
          : 'bg-slate-800 text-slate-100 rounded-bl-xs border border-slate-700/60'
      }`}>
        {/* Sender Name for group messages */}
        {!isMe && showName && (
          <div className="text-[10px] font-bold text-blue-400 mb-0.5">{message.sender_name}</div>
        )}

        {/* Message Body Text */}
        {message.message && <p className="whitespace-pre-wrap leading-relaxed break-words">{message.message}</p>}

        {/* File Attachment */}
        {message.attachment_url && (
          <div className="mt-2 pt-1 border-t border-white/20 dark:border-slate-700/60">
            {message.attachment_is_image ? (
              <div className="rounded-lg overflow-hidden my-1 max-w-[200px] border border-white/20">
                <img src={message.attachment_url} alt="attachment" className="w-full h-auto object-cover max-h-48" />
              </div>
            ) : (
              <a
                href={message.attachment_url}
                target="_blank"
                rel="noreferrer"
                className="flex items-center space-x-2 text-[11px] hover:underline opacity-90 my-1 bg-black/10 p-1.5 rounded-lg"
              >
                <FileText className="w-4 h-4 flex-shrink-0" />
                <span className="truncate">{message.attachment_name || 'Download Attachment'}</span>
                <Download className="w-3 h-3 flex-shrink-0 ml-auto" />
              </a>
            )}
          </div>
        )}

        {/* Time Footer */}
        <div className={`text-[9px] mt-1 text-right opacity-70 ${isMe ? 'text-blue-100' : 'text-slate-400'}`}>
          {formattedTime}
          {message.isSending && ' • Sending...'}
        </div>
      </div>
    </div>
  );
};
