'use client';

import React, { useState, useRef } from 'react';
import { Modal } from '@/components/ui/Modal';
import { useToast } from '@/components/ui/Toast';
import { Upload, FileText, Image as ImageIcon, Video, X, CheckCircle2 } from 'lucide-react';
import { getCsrfToken } from '@/lib/api';

interface FileUploaderProps {
  isOpen: boolean;
  onClose: () => void;
  sessionId: number;
  onUploadSuccess: () => void;
}

export function FileUploader({ isOpen, onClose, sessionId, onUploadSuccess }: FileUploaderProps) {
  const { showToast } = useToast();
  const fileInputRef = useRef<HTMLInputElement>(null);

  const [outputType, setOutputType] = useState('Photo Documentation');
  const [title, setTitle] = useState('');
  const [description, setDescription] = useState('');
  const [selectedFiles, setSelectedFiles] = useState<File[]>([]);
  const [uploading, setUploading] = useState(false);
  const [dragActive, setDragActive] = useState(false);

  const categories = [
    'Before Activity',
    'During Activity',
    'After Activity',
    'Group Photo',
    'Photo Documentation',
    'IEC Material',
    'Attendance Sheet',
    'Presentation',
    'Video Documentation',
    'Other',
  ];

  const handleFiles = (files: FileList | null) => {
    if (!files) return;
    const valid: File[] = [];
    Array.from(files).forEach((f) => {
      const isVideo = f.type.startsWith('video/') || f.name.endsWith('.mp4') || f.name.endsWith('.mov');
      const limit = isVideo ? 50 * 1024 * 1024 : 12 * 1024 * 1024;
      if (f.size > limit) {
        showToast(`File "${f.name}" exceeds limit (${isVideo ? '50MB video' : '12MB document/image'})`, 'warning');
      } else {
        valid.push(f);
      }
    });
    setSelectedFiles((prev) => [...prev, ...valid]);
  };

  const removeFile = (index: number) => {
    setSelectedFiles((prev) => prev.filter((_, i) => i !== index));
  };

  const handleDrag = (e: React.DragEvent) => {
    e.preventDefault();
    e.stopPropagation();
    if (e.type === 'dragenter' || e.type === 'dragover') {
      setDragActive(true);
    } else if (e.type === 'dragleave') {
      setDragActive(false);
    }
  };

  const handleDrop = (e: React.DragEvent) => {
    e.preventDefault();
    e.stopPropagation();
    setDragActive(false);
    if (e.dataTransfer.files && e.dataTransfer.files[0]) {
      handleFiles(e.dataTransfer.files);
    }
  };

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    if (selectedFiles.length === 0) {
      showToast('Please select at least one file to upload.', 'danger');
      return;
    }

    setUploading(true);

    try {
      const formData = new FormData();
      formData.append('session_id', String(sessionId));
      formData.append('output_type', outputType);
      formData.append('title', title);
      formData.append('description', description);

      selectedFiles.forEach((file) => {
        formData.append('files[]', file);
      });

      const csrfToken = await getCsrfToken();

      const response = await fetch('/backend-api/health-education/upload.php', {
        method: 'POST',
        headers: {
          'X-CSRF-Token': csrfToken,
        },
        credentials: 'include',
        body: formData,
      });

      const result = await response.json();
      setUploading(false);

      if (result.success) {
        showToast(result.message || 'Files uploaded successfully!', 'success');
        setSelectedFiles([]);
        setTitle('');
        setDescription('');
        onUploadSuccess();
        onClose();
      } else {
        showToast(result.message || 'Upload failed.', 'danger');
      }
    } catch (err: any) {
      setUploading(false);
      showToast(err.message || 'Network error during upload.', 'danger');
    }
  };

  return (
    <Modal isOpen={isOpen} onClose={onClose} title="Upload Documentation Files" maxWidth="lg">
      <form onSubmit={handleSubmit} className="space-y-4">
        <div>
          <label className="block text-xs font-semibold text-slate-700 uppercase tracking-wide mb-1">
            Category / Documentation Type <span className="text-red-500">*</span>
          </label>
          <select
            value={outputType}
            onChange={(e) => setOutputType(e.target.value)}
            className="w-full rounded-lg border border-slate-300 bg-white px-3.5 py-2 text-sm text-slate-900 focus:border-blue-500 focus:ring-1 focus:ring-blue-500"
          >
            {categories.map((cat) => (
              <option key={cat} value={cat}>
                {cat}
              </option>
            ))}
          </select>
        </div>

        <div>
          <label className="block text-xs font-semibold text-slate-700 uppercase tracking-wide mb-1">
            Document / Photo Title (Optional)
          </label>
          <input
            type="text"
            placeholder="e.g., Activity Group Photo, Attendance Sheet Page 1"
            value={title}
            onChange={(e) => setTitle(e.target.value)}
            className="w-full rounded-lg border border-slate-300 bg-white px-3.5 py-2 text-sm text-slate-900 focus:border-blue-500"
          />
        </div>

        {/* Drag & Drop Box */}
        <div>
          <label className="block text-xs font-semibold text-slate-700 uppercase tracking-wide mb-1">
            Select Files <span className="text-red-500">*</span>
          </label>
          <div
            onDragEnter={handleDrag}
            onDragLeave={handleDrag}
            onDragOver={handleDrag}
            onDrop={handleDrop}
            onClick={() => fileInputRef.current?.click()}
            className={`flex flex-col items-center justify-center p-6 border-2 border-dashed rounded-xl cursor-pointer transition-colors ${
              dragActive
                ? 'border-blue-500 bg-blue-50/50'
                : 'border-slate-300 bg-slate-50/50 hover:bg-slate-100/60'
            }`}
          >
            <Upload className="h-8 w-8 text-blue-600 mb-2" />
            <p className="text-sm font-semibold text-slate-800">
              Click to browse or drag & drop files here
            </p>
            <p className="text-xs text-slate-500 mt-1">
              Supports: JPG, PNG, WEBP, PDF, DOC, DOCX, PPT, MP4, MOV (Max 12MB docs/images, 50MB videos)
            </p>
            <input
              ref={fileInputRef}
              type="file"
              multiple
              accept="image/*,.pdf,.doc,.docx,.ppt,.pptx,video/mp4,video/quicktime"
              onChange={(e) => handleFiles(e.target.files)}
              className="hidden"
            />
          </div>
        </div>

        {/* Selected Files List */}
        {selectedFiles.length > 0 && (
          <div className="space-y-2 max-h-40 overflow-y-auto pr-1">
            <p className="text-xs font-semibold text-slate-600">Selected Files ({selectedFiles.length}):</p>
            {selectedFiles.map((file, idx) => (
              <div
                key={idx}
                className="flex items-center justify-between p-2 rounded-lg border border-slate-200 bg-slate-50 text-xs"
              >
                <div className="flex items-center gap-2 overflow-hidden">
                  {file.type.startsWith('image/') ? (
                    <ImageIcon className="h-4 w-4 text-blue-600 shrink-0" />
                  ) : file.type.startsWith('video/') ? (
                    <Video className="h-4 w-4 text-purple-600 shrink-0" />
                  ) : (
                    <FileText className="h-4 w-4 text-emerald-600 shrink-0" />
                  )}
                  <span className="font-medium text-slate-800 truncate">{file.name}</span>
                  <span className="text-slate-400">({(file.size / (1024 * 1024)).toFixed(1)} MB)</span>
                </div>
                <button
                  type="button"
                  onClick={() => removeFile(idx)}
                  className="text-slate-400 hover:text-red-600 p-1"
                >
                  <X className="h-4 w-4" />
                </button>
              </div>
            ))}
          </div>
        )}

        <div className="flex justify-end gap-3 pt-3 border-t border-slate-100">
          <button
            type="button"
            onClick={onClose}
            disabled={uploading}
            className="rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50"
          >
            Cancel
          </button>
          <button
            type="submit"
            disabled={uploading || selectedFiles.length === 0}
            className="inline-flex items-center gap-2 rounded-lg bg-blue-600 px-5 py-2 text-sm font-semibold text-white shadow-sm hover:bg-blue-700 disabled:opacity-50"
          >
            {uploading && <span className="h-4 w-4 border-2 border-white/30 border-t-white rounded-full animate-spin" />}
            {uploading ? 'Uploading...' : 'Start Upload'}
          </button>
        </div>
      </form>
    </Modal>
  );
}
