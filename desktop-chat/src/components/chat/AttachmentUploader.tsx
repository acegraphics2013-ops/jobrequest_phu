import React from 'react';
import { Paperclip, X } from 'lucide-react';

interface AttachmentUploaderProps {
  file: File | null;
  onCancel: () => void;
}

export const AttachmentUploader: React.FC<AttachmentUploaderProps> = ({ file, onCancel }) => {
  if (!file) return null;

  return (
    <div className="flex items-center justify-between px-3 py-1.5 bg-blue-950/40 border-t border-blue-900/50 text-blue-200 text-xs">
      <div className="flex items-center space-x-2 truncate">
        <Paperclip className="w-3.5 h-3.5 flex-shrink-0 text-blue-400" />
        <span className="truncate font-medium">{file.name}</span>
        <span className="text-[10px] text-blue-400/80">({(file.size / 1024).toFixed(1)} KB)</span>
      </div>

      <button
        onClick={onCancel}
        className="p-1 text-blue-300 hover:text-white hover:bg-blue-900/60 rounded-md transition-colors"
        title="Remove attachment"
      >
        <X className="w-3.5 h-3.5" />
      </button>
    </div>
  );
};
