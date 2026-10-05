'use client';

import React, { useState } from 'react';
import { formatFileSize, formatDate } from '@/lib/utils';
import { Modal } from '@/components/ui/Modal';
import { ConfirmDialog } from '@/components/ui/ConfirmDialog';
import { useToast } from '@/components/ui/Toast';
import { fetchApi } from '@/lib/api';
import {
  Image as ImageIcon,
  FileText,
  Video,
  Download,
  Eye,
  Trash2,
  Calendar,
  MapPin,
  Tag,
  ExternalLink,
} from 'lucide-react';

interface OutputFile {
  id: number;
  session_id: number;
  session_title?: string;
  session_date?: string;
  ward_area?: string;
  uploaded_by: number;
  uploader_name?: string;
  output_type: string;
  title: string;
  description?: string;
  original_name: string;
  stored_name: string;
  extension: string;
  mime_type: string;
  file_size: number;
  is_cover?: boolean;
  download_url: string;
  created_at: string;
}

interface DocumentationGalleryProps {
  outputs: OutputFile[];
  onDeleteSuccess?: () => void;
  categories?: string[];
  activeCategory?: string;
  onSelectCategory?: (cat: string) => void;
}

export function DocumentationGallery({
  outputs,
  onDeleteSuccess,
  categories,
  activeCategory = 'All',
  onSelectCategory,
}: DocumentationGalleryProps) {
  const { showToast } = useToast();
  const [previewFile, setPreviewFile] = useState<OutputFile | null>(null);
  const [deleteId, setDeleteId] = useState<number | null>(null);
  const [deleting, setDeleting] = useState(false);

  const handleDelete = async () => {
    if (!deleteId) return;
    setDeleting(true);

    const res = await fetchApi('/health-education/delete-output.php', {
      method: 'POST',
      body: JSON.stringify({ output_id: deleteId }),
    });

    setDeleting(false);
    setDeleteId(null);

    if (res.success) {
      showToast('Documentation file deleted successfully.', 'success');
      if (onDeleteSuccess) onDeleteSuccess();
    } else {
      showToast(res.message || 'Failed to delete file.', 'danger');
    }
  };

  return (
    <div className="space-y-4">
      {/* Category Tabs */}
      {categories && onSelectCategory && (
        <div className="flex items-center gap-1.5 overflow-x-auto pb-2 scrollbar-none border-b border-slate-200">
          {categories.map((cat) => {
            const isActive = activeCategory === cat;
            return (
              <button
                key={cat}
                onClick={() => onSelectCategory(cat)}
                className={`whitespace-nowrap rounded-lg px-3.5 py-1.5 text-xs font-semibold transition-colors ${
                  isActive
                    ? 'bg-blue-600 text-white shadow-xs'
                    : 'bg-white text-slate-600 hover:bg-slate-100 border border-slate-200'
                }`}
              >
                {cat}
              </button>
            );
          })}
        </div>
      )}

      {/* Grid Display */}
      {outputs.length === 0 ? (
        <div className="rounded-xl border border-slate-200 bg-white p-12 text-center text-slate-500">
          <ImageIcon className="mx-auto h-12 w-12 text-slate-300 mb-3" />
          <p className="text-base font-bold text-slate-800">No documentation files uploaded yet.</p>
          <p className="text-xs text-slate-500 mt-1">
            Upload photo documentation, attendance sheets, or presentation materials.
          </p>
        </div>
      ) : (
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
          {outputs.map((file) => {
            const isImage = file.mime_type?.startsWith('image/') || ['jpg', 'jpeg', 'png', 'webp'].includes(file.extension);
            const isVideo = file.mime_type?.startsWith('video/') || ['mp4', 'mov'].includes(file.extension);

            return (
              <div
                key={file.id}
                className="group relative flex flex-col justify-between overflow-hidden rounded-xl border border-slate-200 bg-white shadow-xs hover:shadow-md transition-all"
              >
                {/* Media Preview Box */}
                <div className="relative h-44 w-full bg-slate-100 overflow-hidden flex items-center justify-center">
                  {isImage ? (
                    <img
                      src={file.download_url}
                      alt={file.title}
                      className="h-full w-full object-cover group-hover:scale-105 transition-transform duration-300"
                    />
                  ) : isVideo ? (
                    <div className="flex flex-col items-center justify-center text-purple-600">
                      <Video className="h-10 w-10" />
                      <span className="text-xs font-semibold mt-1">Video Output</span>
                    </div>
                  ) : (
                    <div className="flex flex-col items-center justify-center text-blue-600">
                      <FileText className="h-10 w-10" />
                      <span className="text-xs font-semibold uppercase tracking-wider mt-1">
                        {file.extension} Document
                      </span>
                    </div>
                  )}

                  {/* Category Pill Badge */}
                  <span className="absolute top-2.5 left-2.5 rounded-full bg-slate-900/80 px-2.5 py-0.5 text-[10px] font-semibold text-white backdrop-blur-xs">
                    {file.output_type}
                  </span>
                </div>

                {/* Info Content */}
                <div className="p-4 flex-1 flex flex-col justify-between space-y-3">
                  <div>
                    <h4 className="font-bold text-slate-900 text-sm line-clamp-1">{file.title}</h4>
                    {file.session_title && (
                      <p className="text-xs text-slate-500 line-clamp-1 mt-0.5">
                        {file.session_title}
                      </p>
                    )}
                  </div>

                  <div className="flex items-center justify-between text-[11px] text-slate-400 border-t border-slate-100 pt-2.5">
                    <span>{formatFileSize(file.file_size)}</span>
                    <span>{formatDate(file.created_at)}</span>
                  </div>

                  {/* Action Buttons */}
                  <div className="flex items-center gap-1.5 pt-1">
                    <button
                      onClick={() => setPreviewFile(file)}
                      className="flex-1 inline-flex items-center justify-center gap-1 rounded-lg border border-slate-200 bg-slate-50 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-100 hover:text-blue-600 transition-colors"
                    >
                      <Eye className="h-3.5 w-3.5" />
                      Preview
                    </button>
                    <a
                      href={file.download_url}
                      download={file.original_name}
                      target="_blank"
                      rel="noopener noreferrer"
                      className="inline-flex items-center justify-center rounded-lg border border-slate-200 bg-white p-1.5 text-slate-600 hover:bg-slate-50 hover:text-blue-600"
                      title="Download File"
                    >
                      <Download className="h-4 w-4" />
                    </a>
                    <button
                      onClick={() => setDeleteId(file.id)}
                      className="inline-flex items-center justify-center rounded-lg border border-slate-200 bg-white p-1.5 text-slate-400 hover:bg-red-50 hover:text-red-600"
                      title="Delete File"
                    >
                      <Trash2 className="h-4 w-4" />
                    </button>
                  </div>
                </div>
              </div>
            );
          })}
        </div>
      )}

      {/* Preview Modal */}
      {previewFile && (
        <Modal
          isOpen={!!previewFile}
          onClose={() => setPreviewFile(null)}
          title={previewFile.title}
          maxWidth="4xl"
        >
          <div className="space-y-4">
            <div className="max-h-[60vh] flex items-center justify-center bg-slate-950 rounded-xl overflow-hidden p-2">
              {previewFile.mime_type?.startsWith('image/') || ['jpg', 'jpeg', 'png', 'webp'].includes(previewFile.extension) ? (
                <img
                  src={previewFile.download_url}
                  alt={previewFile.title}
                  className="max-h-[55vh] w-auto object-contain rounded-lg"
                />
              ) : previewFile.mime_type?.startsWith('video/') || ['mp4', 'mov'].includes(previewFile.extension) ? (
                <video controls src={previewFile.download_url} className="max-h-[55vh] w-full rounded-lg" />
              ) : (
                <div className="p-12 text-center text-slate-300">
                  <FileText className="mx-auto h-16 w-16 mb-4 text-blue-400" />
                  <p className="text-lg font-bold">{previewFile.original_name}</p>
                  <p className="text-xs text-slate-400 mt-1">Preview not inlineable. Click download to view.</p>
                </div>
              )}
            </div>

            <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4 border-t border-slate-200 pt-4">
              <div>
                <span className="inline-block rounded-md bg-blue-50 px-2.5 py-1 text-xs font-bold text-blue-700">
                  {previewFile.output_type}
                </span>
                <p className="text-sm font-semibold text-slate-800 mt-1">{previewFile.original_name}</p>
                <p className="text-xs text-slate-500">
                  Uploaded by {previewFile.uploader_name} on {formatDate(previewFile.created_at)} ({formatFileSize(previewFile.file_size)})
                </p>
              </div>

              <a
                href={previewFile.download_url}
                download={previewFile.original_name}
                target="_blank"
                rel="noopener noreferrer"
                className="inline-flex items-center gap-2 rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-700"
              >
                <Download className="h-4 w-4" />
                Download File
              </a>
            </div>
          </div>
        </Modal>
      )}

      {/* Delete Confirmation */}
      <ConfirmDialog
        isOpen={!!deleteId}
        onClose={() => setDeleteId(null)}
        onConfirm={handleDelete}
        title="Delete Documentation Output"
        message="Are you sure you want to delete this documentation file? This action cannot be undone."
        confirmText="Yes, Delete File"
        confirmVariant="danger"
        loading={deleting}
      />
    </div>
  );
}
