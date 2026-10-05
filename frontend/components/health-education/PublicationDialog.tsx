'use client';

import React, { useState } from 'react';
import { Modal } from '@/components/ui/Modal';
import { useToast } from '@/components/ui/Toast';
import { fetchApi } from '@/lib/api';
import { Send, Globe, Share2, MessageCircle } from 'lucide-react';

interface PublicationDialogProps {
  isOpen: boolean;
  onClose: () => void;
  activityId: number;
  activityTitle: string;
  onPublishSuccess: () => void;
}

export function PublicationDialog({
  isOpen,
  onClose,
  activityId,
  activityTitle,
  onPublishSuccess,
}: PublicationDialogProps) {
  const { showToast } = useToast();
  const [destination, setDestination] = useState<'Both' | 'Facebook' | 'Website' | 'Internal Documentation Only'>('Both');
  const [notes, setNotes] = useState('');
  const [submitting, setSubmitting] = useState(false);

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    setSubmitting(true);

    const res = await fetchApi('/health-education/publish.php', {
      method: 'POST',
      body: JSON.stringify({
        id: activityId,
        destination,
        publication_notes: notes,
      }),
    });

    setSubmitting(false);

    if (res.success) {
      showToast(res.message || 'Activity sent to Publication System successfully!', 'success');
      onPublishSuccess();
      onClose();
    } else {
      showToast(res.message || 'Failed to submit publication request.', 'danger');
    }
  };

  return (
    <Modal isOpen={isOpen} onClose={onClose} title="Send Activity for Publication" maxWidth="md">
      <form onSubmit={handleSubmit} className="space-y-4">
        <div className="rounded-lg bg-blue-50 border border-blue-100 p-3 text-xs text-blue-900">
          <p className="font-bold">Transfer Activity to HPU Publication System</p>
          <p className="mt-0.5 text-blue-700">
            This activity summary, health topic, venue, objectives, and cover outputs will be transferred to the existing HPU Publication queue.
          </p>
        </div>

        <div>
          <label className="block text-xs font-semibold text-slate-700 uppercase tracking-wide mb-1">
            Target Destination <span className="text-red-500">*</span>
          </label>
          <div className="grid grid-cols-2 gap-2">
            {[
              { id: 'Both', label: 'Both (FB & Web)', icon: Share2 },
              { id: 'Facebook', label: 'Facebook Only', icon: MessageCircle },
              { id: 'Website', label: 'Website Only', icon: Globe },
              { id: 'Internal Documentation Only', label: 'Internal Only', icon: Send },
            ].map((item) => {
              const Icon = item.icon;
              const isSelected = destination === item.id;
              return (
                <button
                  type="button"
                  key={item.id}
                  onClick={() => setDestination(item.id as any)}
                  className={`flex items-center gap-2 rounded-lg border p-3 text-xs font-bold transition-all text-left ${
                    isSelected
                      ? 'border-blue-600 bg-blue-50 text-blue-700 ring-1 ring-blue-600'
                      : 'border-slate-200 bg-white text-slate-700 hover:bg-slate-50'
                  }`}
                >
                  <Icon className="h-4 w-4 shrink-0 text-blue-600" />
                  <span>{item.label}</span>
                </button>
              );
            })}
          </div>
        </div>

        <div>
          <label className="block text-xs font-semibold text-slate-700 uppercase tracking-wide mb-1">
            Additional Instructions / Notes
          </label>
          <textarea
            rows={3}
            placeholder="Special instructions for HPU graphics / media team..."
            value={notes}
            onChange={(e) => setNotes(e.target.value)}
            className="w-full rounded-lg border border-slate-300 bg-white p-2.5 text-xs text-slate-900 focus:border-blue-500"
          />
        </div>

        <div className="flex justify-end gap-3 pt-3 border-t border-slate-100">
          <button
            type="button"
            onClick={onClose}
            disabled={submitting}
            className="rounded-lg border border-slate-300 bg-white px-4 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-50"
          >
            Cancel
          </button>
          <button
            type="submit"
            disabled={submitting}
            className="inline-flex items-center gap-2 rounded-lg bg-blue-600 px-5 py-2 text-xs font-semibold text-white shadow-sm hover:bg-blue-700 disabled:opacity-50"
          >
            {submitting && <span className="h-3.5 w-3.5 border-2 border-white/30 border-t-white rounded-full animate-spin" />}
            {submitting ? 'Submitting...' : 'Confirm Publication Request'}
          </button>
        </div>
      </form>
    </Modal>
  );
}
