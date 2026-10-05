'use client';

import React, { useState } from 'react';
import { useAuth } from '@/lib/auth-context';
import { fetchApi } from '@/lib/api';
import { Sidebar } from '@/components/layout/Sidebar';
import { Navbar } from '@/components/layout/Navbar';
import { User, Mail, ShieldCheck, Lock, Building, CheckCircle2, AlertCircle } from 'lucide-react';

export default function ProfilePage() {
  const { user, refreshUser } = useAuth();
  const [name, setName] = useState(user?.name || '');
  const [department, setDepartment] = useState(user?.department || '');
  const [password, setPassword] = useState('');
  const [message, setMessage] = useState<{ text: string; isError: boolean } | null>(null);
  const [saving, setSaving] = useState(false);

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    setMessage(null);
    setSaving(true);

    const res = await fetchApi('/profile/update.php', {
      method: 'POST',
      body: JSON.stringify({
        name,
        department,
        password: password || undefined,
      }),
    });

    if (res.success) {
      setMessage({ text: 'Profile updated successfully!', isError: false });
      setPassword('');
      await refreshUser();
    } else {
      setMessage({ text: res.message || 'Failed to update profile.', isError: true });
    }
    setSaving(false);
  };

  return (
    <div className="flex min-h-screen bg-slate-50">
      <Sidebar />
      <div className="flex-1 flex flex-col min-w-0">
        <Navbar />

        <main className="p-6 space-y-6 max-w-4xl mx-auto w-full">
          <div>
            <h1 className="text-xl font-bold text-slate-900 tracking-tight">Account Profile & Security</h1>
            <p className="text-xs text-slate-500">Manage your personal profile details and password</p>
          </div>

          {message && (
            <div
              className={`p-4 rounded-xl text-xs flex items-center gap-2 border ${message.isError
                  ? 'bg-rose-50 text-rose-700 border-rose-200'
                  : 'bg-emerald-50 text-emerald-700 border-emerald-200'
                }`}
            >
              {message.isError ? (
                <AlertCircle className="w-4 h-4 shrink-0 text-rose-500" />
              ) : (
                <CheckCircle2 className="w-4 h-4 shrink-0 text-emerald-500" />
              )}
              <span>{message.text}</span>
            </div>
          )}

          <div className="bg-white rounded-2xl border border-slate-200 shadow-xs p-6 space-y-6">
            {/* Header info */}
            <div className="flex items-center gap-4 pb-6 border-b border-slate-100">
              <div className="w-14 h-14 rounded-2xl bg-teal-600 text-white font-bold text-xl flex items-center justify-center uppercase shadow-sm">
                {user?.name?.charAt(0) || 'U'}
              </div>
              <div>
                <h2 className="text-base font-bold text-slate-900">{user?.name}</h2>
                <p className="text-xs text-slate-500">{user?.email}</p>
                <div className="flex items-center gap-2 mt-1">
                  <span className="capitalize text-[10px] px-2 py-0.5 rounded font-semibold bg-teal-50 text-teal-700 border border-teal-200">
                    Role: {user?.role}
                  </span>
                  <span className="text-xs text-slate-400">•</span>
                  <span className="text-xs text-slate-600 font-medium">{user?.department}</span>
                </div>
              </div>
            </div>

            {/* Profile Form */}
            <form onSubmit={handleSubmit} className="space-y-4">
              <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                  <label className="block text-xs font-semibold text-slate-700 mb-1">Full Name</label>
                  <input
                    type="text"
                    required
                    value={name}
                    onChange={(e) => setName(e.target.value)}
                    className="w-full bg-slate-50 border border-slate-200 text-xs rounded-xl px-3.5 py-2.5 focus:outline-none focus:border-teal-500"
                  />
                </div>

                <div>
                  <label className="block text-xs font-semibold text-slate-700 mb-1">Email Address</label>
                  <input
                    type="email"
                    disabled
                    value={user?.email || ''}
                    className="w-full bg-slate-100 border border-slate-200 text-xs text-slate-500 rounded-xl px-3.5 py-2.5 cursor-not-allowed"
                  />
                </div>
              </div>

              <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                  <label className="block text-xs font-semibold text-slate-700 mb-1">Department</label>
                  <input
                    type="text"
                    value={department}
                    onChange={(e) => setDepartment(e.target.value)}
                    className="w-full bg-slate-50 border border-slate-200 text-xs rounded-xl px-3.5 py-2.5 focus:outline-none focus:border-teal-500"
                  />
                </div>

                <div>
                  <label className="block text-xs font-semibold text-slate-700 mb-1">
                    New Password <span className="text-slate-400 font-normal">(leave blank to keep current)</span>
                  </label>
                  <input
                    type="password"
                    value={password}
                    onChange={(e) => setPassword(e.target.value)}
                    placeholder="••••••••"
                    className="w-full bg-slate-50 border border-slate-200 text-xs rounded-xl px-3.5 py-2.5 focus:outline-none focus:border-teal-500"
                  />
                </div>
              </div>

              <div className="pt-4 flex justify-end">
                <button
                  type="submit"
                  disabled={saving}
                  className="bg-teal-600 hover:bg-teal-500 disabled:opacity-50 text-white font-medium text-xs px-5 py-2.5 rounded-xl transition-all shadow-xs"
                >
                  {saving ? 'Saving...' : 'Save Profile Changes'}
                </button>
              </div>
            </form>
          </div>
        </main>
      </div>
    </div>
  );
}
