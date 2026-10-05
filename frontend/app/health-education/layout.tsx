'use client';

import React, { useEffect, useState } from 'react';
import { AppSidebar } from '@/components/layout/AppSidebar';
import { TopHeader } from '@/components/layout/TopHeader';
import { ToastProvider } from '@/components/ui/Toast';
import { fetchApi } from '@/lib/api';
import { WhePermissions, defaultPermissions } from '@/lib/permissions';
import { useAuth } from '@/lib/auth-context';
import { useRouter } from 'next/navigation';

export default function HealthEducationLayout({
  children,
}: {
  children: React.ReactNode;
}) {
  const { user, loading } = useAuth();
  const router = useRouter();
  const [mobileOpen, setMobileOpen] = useState(false);
  const [permissions, setPermissions] = useState<WhePermissions>(defaultPermissions);

  useEffect(() => {
    if (!loading && !user) {
      router.push('/login');
      return;
    }

    const loadPermissions = async () => {
      const res = await fetchApi<{ permissions: WhePermissions }>('/health-education/permissions.php');
      if (res.success && res.data?.permissions) {
        setPermissions(res.data.permissions);
      }
    };

    if (user) {
      loadPermissions();
    }
  }, [user, loading, router]);

  if (loading) {
    return (
      <div className="flex h-screen w-screen items-center justify-center bg-slate-50">
        <div className="flex flex-col items-center gap-3">
          <div className="h-10 w-10 border-4 border-blue-600 border-t-transparent rounded-full animate-spin" />
          <p className="text-sm font-semibold text-slate-600">Loading Health Education Module...</p>
        </div>
      </div>
    );
  }

  return (
    <ToastProvider>
      <div className="flex h-screen w-screen overflow-hidden bg-slate-50 text-slate-900">
        <AppSidebar
          permissions={permissions}
          mobileOpen={mobileOpen}
          setMobileOpen={setMobileOpen}
        />

        <div className="flex flex-1 flex-col overflow-hidden">
          <TopHeader onMobileMenuToggle={() => setMobileOpen(!mobileOpen)} />

          <main className="flex-1 overflow-y-auto p-4 sm:p-6 lg:p-8">
            <div className="mx-auto max-w-7xl">{children}</div>
          </main>
        </div>
      </div>
    </ToastProvider>
  );
}
