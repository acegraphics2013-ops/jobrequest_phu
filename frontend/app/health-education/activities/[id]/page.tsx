'use client';

import React, { useEffect, useState } from 'react';
import { useParams, useRouter } from 'next/navigation';
import Link from 'next/link';
import { fetchApi } from '@/lib/api';
import { useToast } from '@/components/ui/Toast';
import { formatDate, formatDateTime } from '@/lib/utils';
import { StatusBadge } from '@/components/ui/StatusBadge';
import { DocumentationChecklist } from '@/components/health-education/DocumentationChecklist';
import { DocumentationGallery } from '@/components/health-education/DocumentationGallery';
import { ActivityTimeline } from '@/components/health-education/ActivityTimeline';
import { FileUploader } from '@/components/health-education/FileUploader';
import { PublicationDialog } from '@/components/health-education/PublicationDialog';
import { ActivityForm } from '@/components/health-education/ActivityForm';

import {
  ArrowLeft,
  Edit,
  Upload,
  CheckCircle2,
  XCircle,
  Send,
  Printer,
  Archive,
  Calendar,
  Clock,
  MapPin,
  Building,
  Users,
  BookOpen,
  FileText,
  MessageSquare,
  ChevronRight,
  Sparkles,
} from 'lucide-react';

export default function ActivityDetailsPage() {
  const params = useParams();
  const router = useRouter();
  const { showToast } = useToast();
  const id = Number(params.id);

  const [loading, setLoading] = useState(true);
  const [data, setData] = useState<any>(null);
  const [isEditing, setIsEditing] = useState(false);
  const [uploadOpen, setUploadOpen] = useState(false);
  const [pubOpen, setPubOpen] = useState(false);
  const [actionLoading, setActionLoading] = useState(false);

  const loadDetails = async () => {
    setLoading(true);
    const res = await fetchApi(`/health-education/activity.php?id=${id}`);
    if (res.success && res.data) {
      setData(res.data);
    } else {
      showToast(res.message || 'Failed to load activity details.', 'danger');
    }
    setLoading(false);
  };

  useEffect(() => {
    if (id) loadDetails();
  }, [id]);

  const handleStatusChange = async (newStatus: string, notes = '') => {
    setActionLoading(true);
    const res = await fetchApi('/health-education/update-status.php', {
      method: 'POST',
      body: JSON.stringify({
        id,
        status: newStatus,
        notes,
      }),
    });
    setActionLoading(false);

    if (res.success) {
      showToast(res.message || `Activity status updated to ${newStatus}`, 'success');
      loadDetails();
    } else {
      showToast(res.message || 'Failed to update status.', 'danger');
    }
  };

  const handlePrint = () => {
    window.print();
  };

  if (loading) {
    return (
      <div className="flex h-96 items-center justify-center">
        <div className="h-8 w-8 border-4 border-blue-600 border-t-transparent rounded-full animate-spin" />
      </div>
    );
  }

  if (!data?.activity) {
    return (
      <div className="p-12 text-center bg-white rounded-xl border border-slate-200 space-y-4">
        <p className="text-lg font-bold text-slate-800">Activity record not found.</p>
        <Link
          href="/health-education/activities"
          className="inline-flex items-center gap-2 rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white"
        >
          <ArrowLeft className="h-4 w-4" />
          Back to Activities
        </Link>
      </div>
    );
  }

  const act = data.activity;
  const outputs = data.outputs || [];
  const checklist = data.checklist || [];
  const timeline = data.timeline || [];
  const permissions = data.userPermissions || {};

  if (isEditing) {
    return (
      <div className="space-y-4">
        <button
          onClick={() => setIsEditing(false)}
          className="inline-flex items-center gap-1.5 text-xs font-semibold text-slate-600 hover:text-blue-600"
        >
          <ArrowLeft className="h-4 w-4" />
          Cancel Edit & Return to View
        </button>
        <ActivityForm initialData={act} isEdit={true} />
      </div>
    );
  }

  return (
    <div className="space-y-6 print:p-0 print:space-y-4">
      {/* Top Header & Breadcrumbs */}
      <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between border-b border-slate-200 pb-4 print:hidden">
        <div>
          <div className="flex items-center gap-2 text-xs text-slate-500 mb-1">
            <Link href="/health-education/activities" className="hover:text-blue-600">
              Activities
            </Link>
            <ChevronRight className="h-3 w-3" />
            <span className="font-semibold text-slate-700">#{act.id}</span>
          </div>
          <div className="flex items-center gap-3">
            <h1 className="text-2xl font-bold tracking-tight text-slate-900">{act.title}</h1>
            <StatusBadge status={act.status} />
          </div>
        </div>

        {/* Action Button Toolbar */}
        <div className="flex flex-wrap items-center gap-2">
          {permissions.canEdit && (
            <button
              onClick={() => setIsEditing(true)}
              className="inline-flex items-center gap-1.5 rounded-lg border border-slate-300 bg-white px-3.5 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-50 shadow-xs"
            >
              <Edit className="h-3.5 w-3.5 text-blue-600" />
              Edit
            </button>
          )}

          {permissions.canUpload && (
            <button
              onClick={() => setUploadOpen(true)}
              className="inline-flex items-center gap-1.5 rounded-lg border border-slate-300 bg-white px-3.5 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-50 shadow-xs"
            >
              <Upload className="h-3.5 w-3.5 text-purple-600" />
              Upload Documentation
            </button>
          )}

          {permissions.canSubmit && (
            <button
              onClick={() => handleStatusChange('For Review')}
              disabled={actionLoading}
              className="inline-flex items-center gap-1.5 rounded-lg bg-blue-600 px-4 py-2 text-xs font-semibold text-white shadow-xs hover:bg-blue-700"
            >
              <Send className="h-3.5 w-3.5" />
              Submit for Review
            </button>
          )}

          {permissions.canApprove && (
            <>
              <button
                onClick={() => handleStatusChange('Approved')}
                disabled={actionLoading}
                className="inline-flex items-center gap-1.5 rounded-lg bg-emerald-600 px-4 py-2 text-xs font-semibold text-white shadow-xs hover:bg-emerald-700"
              >
                <CheckCircle2 className="h-3.5 w-3.5" />
                Approve
              </button>
              <button
                onClick={() => handleStatusChange('For Revision')}
                disabled={actionLoading}
                className="inline-flex items-center gap-1.5 rounded-lg border border-orange-300 bg-orange-50 px-3.5 py-2 text-xs font-semibold text-orange-700 hover:bg-orange-100"
              >
                <XCircle className="h-3.5 w-3.5" />
                Return for Revision
              </button>
            </>
          )}

          {permissions.canPublish && (
            <button
              onClick={() => setPubOpen(true)}
              className="inline-flex items-center gap-1.5 rounded-lg bg-purple-600 px-4 py-2 text-xs font-semibold text-white shadow-xs hover:bg-purple-700"
            >
              <Sparkles className="h-3.5 w-3.5" />
              Send to Publication
            </button>
          )}

          <button
            onClick={handlePrint}
            className="inline-flex items-center gap-1.5 rounded-lg border border-slate-300 bg-white px-3.5 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-50"
          >
            <Printer className="h-3.5 w-3.5" />
            Print
          </button>

          {permissions.canArchive && (
            <button
              onClick={() => handleStatusChange('Archived')}
              disabled={actionLoading}
              className="inline-flex items-center gap-1.5 rounded-lg border border-slate-300 bg-slate-100 px-3 py-2 text-xs font-semibold text-slate-600 hover:bg-slate-200"
            >
              <Archive className="h-3.5 w-3.5" />
              Archive
            </button>
          )}
        </div>
      </div>

      {/* Main Details Grid */}
      <div className="grid grid-cols-1 gap-6 lg:grid-cols-3">
        {/* Left 2 Columns: Activity Information & Details */}
        <div className="lg:col-span-2 space-y-6">
          {/* Main Info Card */}
          <div className="rounded-xl border border-slate-200 bg-white p-6 shadow-xs space-y-5">
            <div className="flex items-center justify-between border-b border-slate-100 pb-3">
              <span className="inline-block rounded-md bg-blue-50 px-3 py-1 text-xs font-bold text-blue-700">
                {act.activity_type}
              </span>
              <span className="text-xs text-slate-500 font-medium">Submitted by {act.submitter_name}</span>
            </div>

            <div className="grid grid-cols-2 gap-4 text-xs sm:grid-cols-3">
              <div>
                <span className="text-slate-400 block font-medium">Session Date</span>
                <span className="text-sm font-bold text-slate-900">{formatDate(act.session_date)}</span>
              </div>
              <div>
                <span className="text-slate-400 block font-medium">Activity Time</span>
                <span className="text-sm font-bold text-slate-900">{act.activity_time || 'Not specified'}</span>
              </div>
              <div>
                <span className="text-slate-400 block font-medium">Ward / Area</span>
                <span className="text-sm font-bold text-slate-900">{act.ward_area}</span>
              </div>
              <div>
                <span className="text-slate-400 block font-medium">Lead Office</span>
                <span className="text-sm font-bold text-slate-900">{act.lead_office}</span>
              </div>
              <div>
                <span className="text-slate-400 block font-medium">Partner Office</span>
                <span className="text-sm font-bold text-slate-900">{act.partner_offices || 'None'}</span>
              </div>
              <div>
                <span className="text-slate-400 block font-medium">Facilitator</span>
                <span className="text-sm font-bold text-slate-900">{act.facilitator}</span>
              </div>
            </div>

            <div className="border-t border-slate-100 pt-4">
              <span className="text-xs font-bold text-slate-400 uppercase tracking-wide">Health Topic</span>
              <p className="text-base font-bold text-slate-900 mt-0.5">{act.health_topic}</p>
            </div>
          </div>

          {/* Participant Breakdown Card */}
          <div className="rounded-xl border border-slate-200 bg-white p-6 shadow-xs">
            <div className="flex items-center justify-between border-b border-slate-100 pb-3 mb-4">
              <h3 className="text-base font-bold text-slate-900">Participant Breakdown</h3>
              <span className="text-sm font-extrabold text-emerald-700 bg-emerald-50 px-3 py-1 rounded-lg border border-emerald-200">
                Total: {act.participant_count}
              </span>
            </div>

            <div className="grid grid-cols-2 gap-4 text-center sm:grid-cols-4">
              <div className="rounded-lg bg-slate-50 p-3 border border-slate-100">
                <p className="text-xs text-slate-500 font-semibold">Patients</p>
                <p className="text-xl font-bold text-slate-900 mt-1">{act.patients_count}</p>
              </div>
              <div className="rounded-lg bg-slate-50 p-3 border border-slate-100">
                <p className="text-xs text-slate-500 font-semibold">Watchers / Family</p>
                <p className="text-xl font-bold text-slate-900 mt-1">{act.watchers_count}</p>
              </div>
              <div className="rounded-lg bg-slate-50 p-3 border border-slate-100">
                <p className="text-xs text-slate-500 font-semibold">Employees</p>
                <p className="text-xl font-bold text-slate-900 mt-1">{act.employees_count}</p>
              </div>
              <div className="rounded-lg bg-slate-50 p-3 border border-slate-100">
                <p className="text-xs text-slate-500 font-semibold">Others</p>
                <p className="text-xl font-bold text-slate-900 mt-1">{act.others_count}</p>
              </div>
            </div>
          </div>

          {/* Education Details */}
          <div className="rounded-xl border border-slate-200 bg-white p-6 shadow-xs space-y-4">
            <h3 className="text-base font-bold text-slate-900 border-b border-slate-100 pb-3">
              Education & Content Details
            </h3>

            <div>
              <span className="text-xs font-semibold text-slate-500 uppercase tracking-wide">Teaching Method</span>
              <p className="text-sm font-semibold text-slate-800 mt-1">{act.teaching_method || 'Lecture'}</p>
            </div>

            <div>
              <span className="text-xs font-semibold text-slate-500 uppercase tracking-wide">Objectives</span>
              <p className="text-sm text-slate-700 leading-relaxed mt-1 whitespace-pre-wrap bg-slate-50/70 p-3 rounded-lg border border-slate-100">
                {act.objectives}
              </p>
            </div>

            {act.key_outputs && (
              <div>
                <span className="text-xs font-semibold text-slate-500 uppercase tracking-wide">Key Outputs / Agreements</span>
                <p className="text-sm text-slate-700 leading-relaxed mt-1 whitespace-pre-wrap bg-slate-50/70 p-3 rounded-lg border border-slate-100">
                  {act.key_outputs}
                </p>
              </div>
            )}

            {act.impact_significance && (
              <div>
                <span className="text-xs font-semibold text-slate-500 uppercase tracking-wide">Impact / Significance</span>
                <p className="text-sm text-slate-700 leading-relaxed mt-1 whitespace-pre-wrap bg-slate-50/70 p-3 rounded-lg border border-slate-100">
                  {act.impact_significance}
                </p>
              </div>
            )}
          </div>

          {/* Attached Visual Documentation Gallery */}
          <div className="rounded-xl border border-slate-200 bg-white p-6 shadow-xs space-y-4">
            <div className="flex items-center justify-between border-b border-slate-100 pb-3">
              <h3 className="text-base font-bold text-slate-900">Attached Visual Documentation</h3>
              {permissions.canUpload && (
                <button
                  onClick={() => setUploadOpen(true)}
                  className="inline-flex items-center gap-1 text-xs font-bold text-blue-600 hover:text-blue-700"
                >
                  <Upload className="h-3.5 w-3.5" />
                  Upload Files
                </button>
              )}
            </div>

            <DocumentationGallery outputs={outputs} onDeleteSuccess={loadDetails} />
          </div>
        </div>

        {/* Right Sidebar Column: Completeness & Audit Log */}
        <div className="space-y-6">
          <DocumentationChecklist
            percentage={act.documentation_percentage}
            checklist={checklist}
          />

          <ActivityTimeline timeline={timeline} />
        </div>
      </div>

      {/* Upload Modal */}
      <FileUploader
        isOpen={uploadOpen}
        onClose={() => setUploadOpen(false)}
        sessionId={act.id}
        onUploadSuccess={loadDetails}
      />

      {/* Publication Dialog */}
      <PublicationDialog
        isOpen={pubOpen}
        onClose={() => setPubOpen(false)}
        activityId={act.id}
        activityTitle={act.title}
        onPublishSuccess={loadDetails}
      />
    </div>
  );
}
