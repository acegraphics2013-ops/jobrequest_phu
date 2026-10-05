'use client';

import React, { useState, useEffect } from 'react';
import { useRouter } from 'next/navigation';
import { fetchApi } from '@/lib/api';
import { useToast } from '@/components/ui/Toast';
import { Save, ArrowLeft, Users, Calendar, Clock, MapPin, Building, BookOpen, Layers } from 'lucide-react';

interface ActivityFormProps {
  initialData?: any;
  isEdit?: boolean;
}

export function ActivityForm({ initialData, isEdit = false }: ActivityFormProps) {
  const router = useRouter();
  const { showToast } = useToast();
  const [submitting, setSubmitting] = useState(false);
  const [departments, setDepartments] = useState<Array<{ id: number; name: string }>>([]);

  const [formData, setFormData] = useState({
    activity_type: initialData?.activity_type || 'Health Education and Promotion',
    title: initialData?.title || '',
    title_preset: initialData?.title || 'Health Education and Promotion',
    title_other: '',
    session_date: initialData?.session_date || new Date().toISOString().split('T')[0],
    activity_time_start: '',
    activity_time_end: '',
    ward_area: initialData?.ward_area || '',
    lead_office: initialData?.lead_office || 'Health Promotion Unit',
    partner_offices: initialData?.partner_offices || '',
    health_topic: initialData?.health_topic || '',
    facilitator: initialData?.facilitator || '',
    target_participants: initialData?.target_participants || 'Patients & Watchers',
    patients_count: initialData?.patients_count || 0,
    watchers_count: initialData?.watchers_count || 0,
    employees_count: initialData?.employees_count || 0,
    others_count: initialData?.others_count || 0,
    teaching_method: initialData?.teaching_method || 'Lecture / Discussion',
    objectives: initialData?.objectives || '',
    key_outputs: initialData?.key_outputs || '',
    impact_significance: initialData?.impact_significance || '',
    iec_materials_used: initialData?.iec_materials_used || '',
    iec_quantity: initialData?.iec_quantity || 0,
    remarks: initialData?.remarks || '',
  });

  // Calculate total participants dynamically
  const calculatedTotal =
    Number(formData.patients_count) +
    Number(formData.watchers_count) +
    Number(formData.employees_count) +
    Number(formData.others_count);

  useEffect(() => {
    // Load departments for office dropdown
    fetchApi<{ departments: Array<{ id: number; name: string }> }>('/health-education/departments.php')
      .then((res) => {
        if (res.success && res.data?.departments) {
          setDepartments(res.data.departments);
        }
      });

    // Parse time strings if present
    if (initialData?.activity_time) {
      const parts = initialData.activity_time.split(' to ');
      setFormData((prev) => ({
        ...prev,
        activity_time_start: parts[0] || '',
        activity_time_end: parts[1] || '',
      }));
    }
  }, [initialData]);

  const handleChange = (e: React.ChangeEvent<HTMLInputElement | HTMLSelectElement | HTMLTextAreaElement>) => {
    const { name, value } = e.target;
    setFormData((prev) => ({ ...prev, [name]: value }));
  };

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();

    let finalTitle = formData.title;
    if (!finalTitle && formData.title_preset) {
      finalTitle = formData.title_preset === 'Others' ? formData.title_other : formData.title_preset;
    }

    if (!finalTitle) {
      showToast('Please enter an Activity Title.', 'danger');
      return;
    }

    if (!formData.session_date || !formData.ward_area || !formData.lead_office || !formData.health_topic || !formData.facilitator || !formData.objectives) {
      showToast('Please complete all required fields.', 'danger');
      return;
    }

    setSubmitting(true);

    const payload = {
      ...formData,
      title: finalTitle,
      participant_count: calculatedTotal,
      id: initialData?.id,
    };

    const endpoint = isEdit ? '/health-education/update.php' : '/health-education/create.php';
    const res = await fetchApi(endpoint, {
      method: 'POST',
      body: JSON.stringify(payload),
    });

    setSubmitting(false);

    if (res.success) {
      showToast(isEdit ? 'Activity updated successfully!' : 'Activity saved successfully!', 'success');
      const targetId = res.data?.id || initialData?.id;
      if (targetId) {
        router.push(`/health-education/activities/${targetId}`);
      } else {
        router.push('/health-education/activities');
      }
    } else {
      showToast(res.message || 'Failed to save activity.', 'danger');
    }
  };

  return (
    <form onSubmit={handleSubmit} className="space-y-6">
      {/* Top Bar */}
      <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4 border-b border-slate-200 pb-4">
        <div>
          <h2 className="text-xl font-bold text-slate-900">
            {isEdit ? 'Edit Health Education Activity' : 'Create New Health Education Activity'}
          </h2>
          <p className="text-sm text-slate-500">
            Fill in the details for health education sessions, lectures, campaigns, or screening activities.
          </p>
        </div>

        <div className="flex items-center gap-3">
          <button
            type="button"
            onClick={() => router.back()}
            className="inline-flex items-center gap-2 rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50 transition-colors"
          >
            <ArrowLeft className="h-4 w-4" />
            Cancel
          </button>
          <button
            type="submit"
            disabled={submitting}
            className="inline-flex items-center gap-2 rounded-lg bg-blue-600 px-5 py-2 text-sm font-semibold text-white shadow-sm hover:bg-blue-700 transition-colors disabled:opacity-50"
          >
            {submitting ? (
              <span className="h-4 w-4 border-2 border-white/30 border-t-white rounded-full animate-spin" />
            ) : (
              <Save className="h-4 w-4" />
            )}
            {isEdit ? 'Update Activity' : 'Save Activity'}
          </button>
        </div>
      </div>

      {/* Section 1: Activity Information */}
      <div className="rounded-xl border border-slate-200 bg-white p-6 shadow-xs">
        <div className="flex items-center gap-2.5 border-b border-slate-100 pb-3 mb-5">
          <div className="flex h-8 w-8 items-center justify-center rounded-lg bg-blue-50 text-blue-600 font-bold">
            <BookOpen className="h-4 w-4" />
          </div>
          <h3 className="text-base font-bold text-slate-900">1. Activity Information</h3>
        </div>

        <div className="grid grid-cols-1 gap-5 md:grid-cols-2 lg:grid-cols-3">
          <div>
            <label className="block text-xs font-semibold text-slate-700 uppercase tracking-wide mb-1.5">
              Activity Type <span className="text-red-500">*</span>
            </label>
            <select
              name="activity_type"
              value={formData.activity_type}
              onChange={handleChange}
              className="w-full rounded-lg border border-slate-300 bg-white px-3.5 py-2.5 text-sm text-slate-900 focus:border-blue-500 focus:ring-1 focus:ring-blue-500"
            >
              <option value="Health Education and Promotion">Health Education and Promotion</option>
              <option value="Health Education Session / Lecture">Health Education Session / Lecture</option>
              <option value="Health Promotion Campaign / Advocacy">Health Promotion Campaign / Advocacy</option>
              <option value="Symposium / Health Forum">Symposium / Health Forum</option>
              <option value="Staff Health Education / Wellness Activity">Staff Health Education / Wellness Activity</option>
              <option value="Screening and Early Detection Activity">Screening and Early Detection Activity</option>
              <option value="Community-Based Health Promotion">Community-Based Health Promotion</option>
              <option value="Others">Others</option>
            </select>
          </div>

          <div className="lg:col-span-2">
            <label className="block text-xs font-semibold text-slate-700 uppercase tracking-wide mb-1.5">
              Activity Title <span className="text-red-500">*</span>
            </label>
            <input
              type="text"
              name="title"
              value={formData.title}
              onChange={handleChange}
              placeholder="e.g., Understanding Hypertension: Know Your Numbers"
              className="w-full rounded-lg border border-slate-300 bg-white px-3.5 py-2.5 text-sm text-slate-900 focus:border-blue-500 focus:ring-1 focus:ring-blue-500"
              required
            />
          </div>

          <div>
            <label className="block text-xs font-semibold text-slate-700 uppercase tracking-wide mb-1.5">
              Session Date <span className="text-red-500">*</span>
            </label>
            <input
              type="date"
              name="session_date"
              value={formData.session_date}
              onChange={handleChange}
              className="w-full rounded-lg border border-slate-300 bg-white px-3.5 py-2.5 text-sm text-slate-900 focus:border-blue-500 focus:ring-1 focus:ring-blue-500"
              required
            />
          </div>

          <div>
            <label className="block text-xs font-semibold text-slate-700 uppercase tracking-wide mb-1.5">
              Start Time
            </label>
            <input
              type="time"
              name="activity_time_start"
              value={formData.activity_time_start}
              onChange={handleChange}
              className="w-full rounded-lg border border-slate-300 bg-white px-3.5 py-2.5 text-sm text-slate-900 focus:border-blue-500 focus:ring-1 focus:ring-blue-500"
            />
          </div>

          <div>
            <label className="block text-xs font-semibold text-slate-700 uppercase tracking-wide mb-1.5">
              End Time
            </label>
            <input
              type="time"
              name="activity_time_end"
              value={formData.activity_time_end}
              onChange={handleChange}
              className="w-full rounded-lg border border-slate-300 bg-white px-3.5 py-2.5 text-sm text-slate-900 focus:border-blue-500 focus:ring-1 focus:ring-blue-500"
            />
          </div>

          <div>
            <label className="block text-xs font-semibold text-slate-700 uppercase tracking-wide mb-1.5">
              Ward / Area <span className="text-red-500">*</span>
            </label>
            <input
              type="text"
              name="ward_area"
              value={formData.ward_area}
              onChange={handleChange}
              placeholder="e.g., Male Surgical Ward, OPD Waiting Area"
              className="w-full rounded-lg border border-slate-300 bg-white px-3.5 py-2.5 text-sm text-slate-900 focus:border-blue-500 focus:ring-1 focus:ring-blue-500"
              required
            />
          </div>

          <div>
            <label className="block text-xs font-semibold text-slate-700 uppercase tracking-wide mb-1.5">
              Lead Office <span className="text-red-500">*</span>
            </label>
            <select
              name="lead_office"
              value={formData.lead_office}
              onChange={handleChange}
              className="w-full rounded-lg border border-slate-300 bg-white px-3.5 py-2.5 text-sm text-slate-900 focus:border-blue-500 focus:ring-1 focus:ring-blue-500"
            >
              <option value="Health Promotion Unit">Health Promotion Unit</option>
              {departments.map((dept) => (
                <option key={dept.id} value={dept.name}>
                  {dept.name}
                </option>
              ))}
            </select>
          </div>

          <div>
            <label className="block text-xs font-semibold text-slate-700 uppercase tracking-wide mb-1.5">
              Partner Office / Department
            </label>
            <input
              type="text"
              name="partner_offices"
              value={formData.partner_offices}
              onChange={handleChange}
              placeholder="e.g., Nursing Service, Dietary Dept"
              className="w-full rounded-lg border border-slate-300 bg-white px-3.5 py-2.5 text-sm text-slate-900 focus:border-blue-500 focus:ring-1 focus:ring-blue-500"
            />
          </div>

          <div>
            <label className="block text-xs font-semibold text-slate-700 uppercase tracking-wide mb-1.5">
              Health Topic <span className="text-red-500">*</span>
            </label>
            <input
              type="text"
              name="health_topic"
              value={formData.health_topic}
              onChange={handleChange}
              placeholder="e.g., Hypertension & Stroke Prevention"
              className="w-full rounded-lg border border-slate-300 bg-white px-3.5 py-2.5 text-sm text-slate-900 focus:border-blue-500 focus:ring-1 focus:ring-blue-500"
              required
            />
          </div>

          <div className="lg:col-span-2">
            <label className="block text-xs font-semibold text-slate-700 uppercase tracking-wide mb-1.5">
              Facilitator / Resource Speaker <span className="text-red-500">*</span>
            </label>
            <input
              type="text"
              name="facilitator"
              value={formData.facilitator}
              onChange={handleChange}
              placeholder="e.g., Nurse Maria Santos, RN / Dr. Juan Dela Cruz"
              className="w-full rounded-lg border border-slate-300 bg-white px-3.5 py-2.5 text-sm text-slate-900 focus:border-blue-500 focus:ring-1 focus:ring-blue-500"
              required
            />
          </div>
        </div>
      </div>

      {/* Section 2: Participants */}
      <div className="rounded-xl border border-slate-200 bg-white p-6 shadow-xs">
        <div className="flex items-center justify-between border-b border-slate-100 pb-3 mb-5">
          <div className="flex items-center gap-2.5">
            <div className="flex h-8 w-8 items-center justify-center rounded-lg bg-emerald-50 text-emerald-600 font-bold">
              <Users className="h-4 w-4" />
            </div>
            <h3 className="text-base font-bold text-slate-900">2. Participant Breakdown</h3>
          </div>
          <div className="rounded-lg bg-emerald-50 px-3 py-1 text-sm font-bold text-emerald-700 border border-emerald-200">
            Total: {calculatedTotal} Participants
          </div>
        </div>

        <div className="grid grid-cols-1 gap-5 sm:grid-cols-2 md:grid-cols-4">
          <div>
            <label className="block text-xs font-semibold text-slate-700 uppercase tracking-wide mb-1.5">
              Patients
            </label>
            <input
              type="number"
              min="0"
              name="patients_count"
              value={formData.patients_count}
              onChange={handleChange}
              className="w-full rounded-lg border border-slate-300 bg-white px-3.5 py-2.5 text-sm text-slate-900 focus:border-blue-500 focus:ring-1 focus:ring-blue-500"
            />
          </div>

          <div>
            <label className="block text-xs font-semibold text-slate-700 uppercase tracking-wide mb-1.5">
              Watchers / Family
            </label>
            <input
              type="number"
              min="0"
              name="watchers_count"
              value={formData.watchers_count}
              onChange={handleChange}
              className="w-full rounded-lg border border-slate-300 bg-white px-3.5 py-2.5 text-sm text-slate-900 focus:border-blue-500 focus:ring-1 focus:ring-blue-500"
            />
          </div>

          <div>
            <label className="block text-xs font-semibold text-slate-700 uppercase tracking-wide mb-1.5">
              Employees / Staff
            </label>
            <input
              type="number"
              min="0"
              name="employees_count"
              value={formData.employees_count}
              onChange={handleChange}
              className="w-full rounded-lg border border-slate-300 bg-white px-3.5 py-2.5 text-sm text-slate-900 focus:border-blue-500 focus:ring-1 focus:ring-blue-500"
            />
          </div>

          <div>
            <label className="block text-xs font-semibold text-slate-700 uppercase tracking-wide mb-1.5">
              Others
            </label>
            <input
              type="number"
              min="0"
              name="others_count"
              value={formData.others_count}
              onChange={handleChange}
              className="w-full rounded-lg border border-slate-300 bg-white px-3.5 py-2.5 text-sm text-slate-900 focus:border-blue-500 focus:ring-1 focus:ring-blue-500"
            />
          </div>
        </div>
      </div>

      {/* Section 3: Education Details */}
      <div className="rounded-xl border border-slate-200 bg-white p-6 shadow-xs">
        <div className="flex items-center gap-2.5 border-b border-slate-100 pb-3 mb-5">
          <div className="flex h-8 w-8 items-center justify-center rounded-lg bg-amber-50 text-amber-600 font-bold">
            <Layers className="h-4 w-4" />
          </div>
          <h3 className="text-base font-bold text-slate-900">3. Education & Content Details</h3>
        </div>

        <div className="space-y-5">
          <div>
            <label className="block text-xs font-semibold text-slate-700 uppercase tracking-wide mb-1.5">
              Teaching Method
            </label>
            <input
              type="text"
              name="teaching_method"
              value={formData.teaching_method}
              onChange={handleChange}
              placeholder="e.g., Interactive Lecture, Q&A, Demonstration, Video Screening"
              className="w-full rounded-lg border border-slate-300 bg-white px-3.5 py-2.5 text-sm text-slate-900 focus:border-blue-500 focus:ring-1 focus:ring-blue-500"
            />
          </div>

          <div>
            <label className="block text-xs font-semibold text-slate-700 uppercase tracking-wide mb-1.5">
              Objectives <span className="text-red-500">*</span>
            </label>
            <textarea
              name="objectives"
              rows={3}
              value={formData.objectives}
              onChange={handleChange}
              placeholder="State the primary learning objectives and key takeaways for participants..."
              className="w-full rounded-lg border border-slate-300 bg-white px-3.5 py-2.5 text-sm text-slate-900 focus:border-blue-500 focus:ring-1 focus:ring-blue-500"
              required
            />
          </div>

          <div className="grid grid-cols-1 gap-5 md:grid-cols-2">
            <div>
              <label className="block text-xs font-semibold text-slate-700 uppercase tracking-wide mb-1.5">
                Key Outputs / Agreements
              </label>
              <textarea
                name="key_outputs"
                rows={3}
                value={formData.key_outputs}
                onChange={handleChange}
                placeholder="List key outputs, agreements, or participant responses..."
                className="w-full rounded-lg border border-slate-300 bg-white px-3.5 py-2.5 text-sm text-slate-900 focus:border-blue-500 focus:ring-1 focus:ring-blue-500"
              />
            </div>

            <div>
              <label className="block text-xs font-semibold text-slate-700 uppercase tracking-wide mb-1.5">
                Impact / Significance
              </label>
              <textarea
                name="impact_significance"
                rows={3}
                value={formData.impact_significance}
                onChange={handleChange}
                placeholder="Describe the clinical or health promotion impact..."
                className="w-full rounded-lg border border-slate-300 bg-white px-3.5 py-2.5 text-sm text-slate-900 focus:border-blue-500 focus:ring-1 focus:ring-blue-500"
              />
            </div>
          </div>
        </div>
      </div>

      {/* Section 4: Documentation */}
      <div className="rounded-xl border border-slate-200 bg-white p-6 shadow-xs">
        <div className="flex items-center gap-2.5 border-b border-slate-100 pb-3 mb-5">
          <div className="flex h-8 w-8 items-center justify-center rounded-lg bg-purple-50 text-purple-600 font-bold">
            <Building className="h-4 w-4" />
          </div>
          <h3 className="text-base font-bold text-slate-900">4. IEC Materials & Documentation Notes</h3>
        </div>

        <div className="grid grid-cols-1 gap-5 md:grid-cols-3">
          <div>
            <label className="block text-xs font-semibold text-slate-700 uppercase tracking-wide mb-1.5">
              IEC Materials Used
            </label>
            <input
              type="text"
              name="iec_materials_used"
              value={formData.iec_materials_used}
              onChange={handleChange}
              placeholder="e.g., Hypertension Flyers, Diabetes Pamphlets"
              className="w-full rounded-lg border border-slate-300 bg-white px-3.5 py-2.5 text-sm text-slate-900 focus:border-blue-500 focus:ring-1 focus:ring-blue-500"
            />
          </div>

          <div>
            <label className="block text-xs font-semibold text-slate-700 uppercase tracking-wide mb-1.5">
              Quantity Distributed
            </label>
            <input
              type="number"
              min="0"
              name="iec_quantity"
              value={formData.iec_quantity}
              onChange={handleChange}
              className="w-full rounded-lg border border-slate-300 bg-white px-3.5 py-2.5 text-sm text-slate-900 focus:border-blue-500 focus:ring-1 focus:ring-blue-500"
            />
          </div>

          <div>
            <label className="block text-xs font-semibold text-slate-700 uppercase tracking-wide mb-1.5">
              Remarks / Additional Notes
            </label>
            <input
              type="text"
              name="remarks"
              value={formData.remarks}
              onChange={handleChange}
              placeholder="Any additional remarks..."
              className="w-full rounded-lg border border-slate-300 bg-white px-3.5 py-2.5 text-sm text-slate-900 focus:border-blue-500 focus:ring-1 focus:ring-blue-500"
            />
          </div>
        </div>
      </div>
    </form>
  );
}
