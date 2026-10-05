'use client';

import React, { useEffect, useState, use } from 'react';
import { useAuth } from '@/lib/auth-context';
import { fetchApi } from '@/lib/api';
import { Sidebar } from '@/components/layout/Sidebar';
import { Navbar } from '@/components/layout/Navbar';
import { StatusBadge } from '@/components/ui/StatusBadge';
import Link from 'next/link';
import {
  ArrowLeft,
  FileText,
  User,
  Building,
  Calendar,
  MessageSquare,
  Paperclip,
  Clock,
  CheckCircle2,
  AlertCircle,
  Send,
} from 'lucide-react';

interface RequestDetail {
  id: number;
  control_number: string;
  requester_name: string;
  department: string;
  contact: string;
  project_title: string;
  purpose_objective: string;
  request_details: string;
  status: string;
  priority: string;
  assigned_to: string;
  reviewed_by: string;
  approved_by: string;
  created_at: string;
}

interface AttachedFile {
  id: number;
  file_name: string;
  file_path: string;
  uploaded_at: string;
}

interface Comment {
  id: number;
  user_id: number;
  user_name: string;
  comment: string;
  created_at: string;
}

export default function RequestDetailPage({ params }: { params: Promise<{ id: string }> }) {
  const resolvedParams = use(params);
  const requestId = resolvedParams.id;

  const { user } = useAuth();
  const [request, setRequest] = useState<RequestDetail | null>(null);
  const [files, setFiles] = useState<AttachedFile[]>([]);
  const [comments, setComments] = useState<Comment[]>([]);
  const [loading, setLoading] = useState(true);
  const [newComment, setNewComment] = useState('');

  useEffect(() => {
    async function loadDetail() {
      const res = await fetchApi<{
        request: RequestDetail;
        files: AttachedFile[];
        comments: Comment[];
      }>(`/requests/detail.php?id=${requestId}`);

      if (res.success && res.data) {
        setRequest(res.data.request);
        setFiles(res.data.files || []);
        setComments(res.data.comments || []);
      }
      setLoading(false);
    }

    if (user && requestId) {
      loadDetail();
    }
  }, [user, requestId]);

  if (loading || !request) {
    return (
      <div className="flex h-screen items-center justify-center bg-slate-900 text-white">
        <div className="animate-spin rounded-full h-8 w-8 border-t-2 border-b-2 border-teal-500" />
      </div>
    );
  }

  return (
    <div className="flex min-h-screen bg-slate-50">
      <Sidebar />
      <div className="flex-1 flex flex-col min-w-0">
        <Navbar />

        <main className="p-6 space-y-6 max-w-5xl mx-auto w-full">
          {/* Top Bar with Back link */}
          <div className="flex items-center justify-between">
            <Link
              href="/requests"
              className="inline-flex items-center gap-2 text-xs font-medium text-slate-600 hover:text-slate-900 bg-white border border-slate-200 px-3 py-1.5 rounded-xl shadow-xs"
            >
              <ArrowLeft className="w-4 h-4" />
              <span>Back to Requests</span>
            </Link>
            <div className="flex items-center gap-2">
              <span className="text-xs text-slate-400">Control Number:</span>
              <span className="text-sm font-bold text-slate-900">{request.control_number}</span>
            </div>
          </div>

          {/* Main Card */}
          <div className="bg-white rounded-2xl border border-slate-200 shadow-xs overflow-hidden">
            {/* Header */}
            <div className="p-6 border-b border-slate-100 flex flex-col sm:flex-row sm:items-center justify-between gap-4 bg-slate-50/50">
              <div>
                <span className="text-xs font-semibold text-teal-700 bg-teal-50 border border-teal-200 px-2.5 py-0.5 rounded-full uppercase tracking-wide">
                  {request.project_title}
                </span>
                <h1 className="text-xl font-bold text-slate-900 mt-2">{request.project_title}</h1>
                <p className="text-xs text-slate-500 mt-0.5">Submitted on {new Date(request.created_at).toLocaleDateString()}</p>
              </div>

              <div className="flex items-center gap-3">
                <span
                  className={`px-2.5 py-1 rounded-full text-xs font-semibold ${request.priority === 'Urgent'
                      ? 'bg-rose-100 text-rose-700'
                      : request.priority === 'High'
                        ? 'bg-amber-100 text-amber-700'
                        : 'bg-slate-100 text-slate-600'
                    }`}
                >
                  Priority: {request.priority}
                </span>
                <StatusBadge status={request.status} />
              </div>
            </div>

            {/* Info Grid */}
            <div className="p-6 grid grid-cols-1 md:grid-cols-3 gap-6 border-b border-slate-100">
              <div className="flex items-center gap-3">
                <div className="w-9 h-9 rounded-xl bg-slate-100 text-slate-600 flex items-center justify-center">
                  <User className="w-4 h-4" />
                </div>
                <div>
                  <p className="text-[11px] text-slate-400 font-medium">Requester</p>
                  <p className="text-xs font-semibold text-slate-800">{request.requester_name}</p>
                </div>
              </div>

              <div className="flex items-center gap-3">
                <div className="w-9 h-9 rounded-xl bg-slate-100 text-slate-600 flex items-center justify-center">
                  <Building className="w-4 h-4" />
                </div>
                <div>
                  <p className="text-[11px] text-slate-400 font-medium">Department</p>
                  <p className="text-xs font-semibold text-slate-800">{request.department}</p>
                </div>
              </div>

              <div className="flex items-center gap-3">
                <div className="w-9 h-9 rounded-xl bg-slate-100 text-slate-600 flex items-center justify-center">
                  <Calendar className="w-4 h-4" />
                </div>
                <div>
                  <p className="text-[11px] text-slate-400 font-medium">Submission Date</p>
                  <p className="text-xs font-semibold text-slate-800">{new Date(request.created_at).toLocaleDateString()}</p>
                </div>
              </div>
            </div>

            {/* Details Section */}
            <div className="p-6 space-y-6">
              <div>
                <h3 className="text-xs font-bold text-slate-900 uppercase tracking-wider mb-2">Purpose / Objective</h3>
                <p className="text-xs text-slate-700 leading-relaxed bg-slate-50 p-4 rounded-xl border border-slate-100">
                  {request.purpose_objective || 'No purpose specified.'}
                </p>
              </div>

              {request.request_details && (
                <div>
                  <h3 className="text-xs font-bold text-slate-900 uppercase tracking-wider mb-2">Request Details</h3>
                  <p className="text-xs text-slate-700 leading-relaxed bg-slate-50 p-4 rounded-xl border border-slate-100">
                    {request.request_details}
                  </p>
                </div>
              )}

              {/* Attachments */}
              <div>
                <h3 className="text-xs font-bold text-slate-900 uppercase tracking-wider mb-2 flex items-center gap-2">
                  <Paperclip className="w-4 h-4 text-slate-400" />
                  <span>Attachments ({files.length})</span>
                </h3>
                {files.length === 0 ? (
                  <p className="text-xs text-slate-400 italic">No file attachments included.</p>
                ) : (
                  <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    {files.map((file) => (
                      <a
                        key={file.id}
                        href={`http://localhost/jobrequest_phu/${file.file_path}`}
                        target="_blank"
                        rel="noreferrer"
                        className="p-3 bg-slate-50 hover:bg-slate-100 border border-slate-200 rounded-xl text-xs text-teal-700 font-medium flex items-center justify-between transition-colors"
                      >
                        <span className="truncate">{file.file_name}</span>
                        <span className="text-[10px] text-slate-400">Download</span>
                      </a>
                    ))}
                  </div>
                )}
              </div>
            </div>
          </div>
        </main>
      </div>
    </div>
  );
}
