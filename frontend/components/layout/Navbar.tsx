'use client';

import React from 'react';
import { useAuth } from '@/lib/auth-context';
import { LogOut, User as UserIcon, Bell, Search, Building, Menu } from 'lucide-react';
import Link from 'next/link';
import { useSidebar } from '@/components/layout/SidebarContext';

export const Navbar: React.FC = () => {
  const { user, logout } = useAuth();
  const { setMobileOpen } = useSidebar();

  return (
    <header className="h-14 bg-white border-b border-slate-200 px-4 sm:px-6 flex items-center justify-between sticky top-0 z-30 shadow-xs">
      {/* Search / Context info / Mobile toggle */}
      <div className="flex items-center gap-3">
        <button
          type="button"
          onClick={() => setMobileOpen(true)}
          className="lg:hidden p-1.5 text-slate-600 hover:text-slate-900 hover:bg-slate-100 rounded-md transition-colors"
          aria-label="Open Mobile Menu"
        >
          <Menu className="w-5 h-5" />
        </button>

        <div className="hidden sm:flex items-center gap-2 text-xs text-slate-500 bg-slate-50 border border-slate-200 rounded-md px-3 py-1.5 w-64">
          <Search className="w-3.5 h-3.5 text-slate-400" />
          <span>Search requests, users...</span>
        </div>
      </div>

      {/* User Actions & Profile */}
      <div className="flex items-center gap-4">
        {/* Department Tag */}
        {user?.department && (
          <div className="hidden sm:flex items-center gap-1.5 text-xs text-slate-600 bg-slate-100 border border-slate-200 px-2.5 py-1 rounded-md">
            <Building className="w-3.5 h-3.5 text-slate-500" />
            <span className="font-medium">{user.department}</span>
          </div>
        )}

        {/* Notification Icon Link */}
        <Link
          href="/notifications"
          className="p-1.5 text-slate-500 hover:text-slate-700 hover:bg-slate-100 rounded-md transition-colors relative"
          title="Notifications"
        >
          <Bell className="w-4 h-4" />
        </Link>

        {/* Profile Link */}
        <Link
          href="/profile"
          className="flex items-center gap-2 text-xs font-medium text-slate-700 hover:text-teal-700 transition-colors"
        >
          <div className="w-7 h-7 rounded-full bg-teal-100 text-teal-800 flex items-center justify-center font-bold text-xs">
            {user?.name?.charAt(0) || 'U'}
          </div>
          <span className="hidden md:inline">{user?.name}</span>
        </Link>

        {/* Logout Button */}
        <button
          onClick={logout}
          className="flex items-center gap-1.5 text-xs font-medium text-rose-600 hover:text-rose-700 hover:bg-rose-50 px-2.5 py-1.5 rounded-md border border-rose-200 transition-colors"
          title="Sign Out"
        >
          <LogOut className="w-3.5 h-3.5" />
          <span className="hidden sm:inline">Logout</span>
        </button>
      </div>
    </header>
  );
};
