'use client';

import React, { useState } from 'react';
import Link from 'next/link';
import { usePathname } from 'next/navigation';
import {
  LayoutDashboard,
  SquarePlus,
  ClipboardList,
  Images,
  ChartNoAxesCombined,
  ChevronLeft,
  ChevronRight,
  Activity,
  Calendar,
  Bell,
  Home,
  FileText,
  Layers,
} from 'lucide-react';
import { WhePermissions } from '@/lib/permissions';

interface AppSidebarProps {
  permissions?: WhePermissions;
  mobileOpen?: boolean;
  setMobileOpen?: (open: boolean) => void;
}

export function AppSidebar({ permissions, mobileOpen, setMobileOpen }: AppSidebarProps) {
  const pathname = usePathname();
  const [collapsed, setCollapsed] = useState(false);

  const mainNav = [
    { label: 'Main Dashboard', icon: Home, href: '/dashboard' },
  ];

  const healthEduNav = [
    {
      label: 'Overview',
      icon: LayoutDashboard,
      href: '/health-education',
      permKey: 'whe_overview' as keyof WhePermissions,
    },
    {
      label: 'New Activity',
      icon: SquarePlus,
      href: '/health-education/new',
      permKey: 'whe_new' as keyof WhePermissions,
    },
    {
      label: 'Activities',
      icon: ClipboardList,
      href: '/health-education/activities',
      permKey: 'whe_sessions' as keyof WhePermissions,
    },
    {
      label: 'Visual Documentation',
      icon: Images,
      href: '/health-education/documentation',
      permKey: 'whe_visuals' as keyof WhePermissions,
    },
    {
      label: 'Reports',
      icon: ChartNoAxesCombined,
      href: '/health-education/reports',
      permKey: 'whe_reports' as keyof WhePermissions,
    },
  ];

  const sidebarContent = (
    <div className="flex h-full flex-col justify-between bg-slate-900 text-slate-100">
      <div>
        {/* Header Branding */}
        <div className="flex h-16 items-center justify-between border-b border-slate-800 px-4">
          <Link href="/health-education" className="flex items-center gap-3 overflow-hidden">
            <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-blue-600 font-bold text-white shadow-md">
              <Activity className="h-6 w-6" />
            </div>
            {!collapsed && (
              <div className="leading-tight">
                <span className="block text-sm font-bold tracking-wide text-white">HPU Workbench</span>
                <span className="block text-[11px] font-medium text-blue-400">Health Promotion Unit</span>
              </div>
            )}
          </Link>
          <button
            onClick={() => setCollapsed(!collapsed)}
            className="hidden lg:flex h-8 w-8 items-center justify-center rounded-lg text-slate-400 hover:bg-slate-800 hover:text-white transition-colors"
          >
            {collapsed ? <ChevronRight className="h-4 w-4" /> : <ChevronLeft className="h-4 w-4" />}
          </button>
        </div>

        {/* Navigation Sections */}
        <div className="space-y-6 px-3 py-4 overflow-y-auto">
          {/* Main Links */}
          <div>
            {!collapsed && (
              <p className="px-3 text-[10px] font-semibold tracking-wider text-slate-400 uppercase">
                General
              </p>
            )}
            <nav className="mt-1 space-y-1">
              {mainNav.map((item) => {
                const Icon = item.icon;
                const isActive = pathname === item.href;
                return (
                  <Link
                    key={item.href}
                    href={item.href}
                    className={`flex items-center gap-3 rounded-lg px-3 py-2.5 text-sm font-medium transition-colors ${
                      isActive
                        ? 'bg-blue-600 text-white font-semibold shadow-sm'
                        : 'text-slate-300 hover:bg-slate-800 hover:text-white'
                    }`}
                  >
                    <Icon className="h-5 w-5 shrink-0" />
                    {!collapsed && <span>{item.label}</span>}
                  </Link>
                );
              })}
            </nav>
          </div>

          {/* Health Education & Promotion */}
          <div>
            {!collapsed && (
              <p className="px-3 text-[10px] font-semibold tracking-wider text-blue-400 uppercase">
                Health Education & Promotion
              </p>
            )}
            <nav className="mt-1 space-y-1">
              {healthEduNav.map((item) => {
                // Check permissions
                if (permissions && item.permKey && !permissions[item.permKey]) {
                  return null;
                }
                const Icon = item.icon;
                const isActive = pathname === item.href;

                return (
                  <Link
                    key={item.href}
                    href={item.href}
                    onClick={() => setMobileOpen && setMobileOpen(false)}
                    className={`flex items-center gap-3 rounded-lg px-3 py-2.5 text-sm font-medium transition-colors ${
                      isActive
                        ? 'bg-blue-600 text-white font-semibold shadow-sm'
                        : 'text-slate-300 hover:bg-slate-800 hover:text-white'
                    }`}
                  >
                    <Icon className="h-5 w-5 shrink-0" />
                    {!collapsed && <span>{item.label}</span>}
                  </Link>
                );
              })}
            </nav>
          </div>
        </div>
      </div>

      {/* Footer System Badge */}
      <div className="border-t border-slate-800 p-3">
        {!collapsed && (
          <div className="rounded-lg bg-slate-800/60 p-3 text-xs text-slate-400">
            <p className="font-semibold text-slate-200">HPU System v2.5</p>
            <p className="text-[11px] text-slate-400">Health Promotion Unit</p>
          </div>
        )}
      </div>
    </div>
  );

  return (
    <>
      {/* Desktop Sidebar */}
      <aside
        className={`hidden lg:flex flex-col border-r border-slate-800 transition-all duration-300 ${
          collapsed ? 'w-20' : 'w-64'
        } shrink-0`}
      >
        {sidebarContent}
      </aside>

      {/* Mobile Drawer Overlay */}
      {mobileOpen && (
        <div className="fixed inset-0 z-50 lg:hidden flex">
          <div
            className="fixed inset-0 bg-slate-900/60 backdrop-blur-xs"
            onClick={() => setMobileOpen && setMobileOpen(false)}
          />
          <div className="relative flex w-full max-w-xs flex-1 flex-col bg-slate-900 transition-transform">
            {sidebarContent}
          </div>
        </div>
      )}
    </>
  );
}
