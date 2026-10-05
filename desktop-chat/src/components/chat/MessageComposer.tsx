import React, { useState, useRef } from 'react';
import { Paperclip, Send } from 'lucide-react';

interface MessageComposerProps {
  onSend: (text: string, file?: File | null) => void;
  selectedFile: File | null;
  onFileSelect: (file: File | null) => void;
  isSending: boolean;
}

export const MessageComposer: React.FC<MessageComposerProps> = ({
  onSend,
  selectedFile,
  onFileSelect,
  isSending,
}) => {
  const [text, setText] = useState('');
  const fileInputRef = useRef<HTMLInputElement>(null);

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault();
    if ((!text.trim() && !selectedFile) || isSending) return;
    onSend(text.trim(), selectedFile);
    setText('');
  };

  const handleKeyDown = (e: React.KeyboardEvent<HTMLTextAreaElement>) => {
    if (e.key === 'Enter' && !e.shiftKey) {
      e.preventDefault();
      handleSubmit(e);
    }
  };

  return (
    <form onSubmit={handleSubmit} className="p-2.5 bg-slate-900 border-t border-slate-800 flex items-end space-x-2">
      <input
        type="file"
        ref={fileInputRef}
        onChange={(e) => {
          if (e.target.files && e.target.files[0]) {
            onFileSelect(e.target.files[0]);
          }
        }}
        className="hidden"
      />

      <button
        type="button"
        onClick={() => fileInputRef.current?.click()}
        className="p-2 text-slate-400 hover:text-white hover:bg-slate-800 rounded-xl transition-colors flex-shrink-0"
        title="Attach file"
      >
        <Paperclip className="w-4 h-4" />
      </button>

      <textarea
        value={text}
        onChange={(e) => setText(e.target.value)}
        onKeyDown={handleKeyDown}
        placeholder="Type message..."
        rows={1}
        className="flex-1 bg-slate-800 border border-slate-700/80 rounded-xl px-3 py-2 text-xs text-slate-100 placeholder-slate-400 focus:outline-none focus:ring-1 focus:ring-blue-500 resize-none max-h-24 no-scrollbar"
      />

      <button
        type="submit"
        disabled={(!text.trim() && !selectedFile) || isSending}
        className="p-2 bg-blue-600 hover:bg-blue-500 disabled:opacity-40 text-white rounded-xl transition-all shadow-md shadow-blue-600/30 flex-shrink-0 active:scale-95"
        title="Send Message"
      >
        <Send className="w-4 h-4" />
      </button>
    </form>
  );
};
