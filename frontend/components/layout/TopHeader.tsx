'use client';

import React from 'react';
import { Menu, LogOut, User as UserIcon, Bell } from 'lucide-react';
import { useAuth } from '@/lib/auth-context';

interface TopHeaderProps {
  title?: string;
  onMobileMenuToggle?: () => void;
}

export function TopHeader({ title = 'Health Education & Promotion', onMobileMenuToggle }: TopHeaderProps) {
  const { user, logout } = useAuth();

  return (
    <header className="sticky top-0 z-30 flex h-16 w-full items-center justify-between border-b border-slate-200 bg-white/95 px-4 sm:px-6 backdrop-blur-xs">
      <div className="flex items-center gap-3">
        <button
          onClick={onMobileMenuToggle}
          className="rounded-lg p-2 text-slate-600 hover:bg-slate-100 lg:hidden"
        >
          <Menu className="h-5 w-5" />
        </button>

        <div>
          <h1 className="text-lg font-bold tracking-tight text-slate-900 sm:text-xl">
            {title}
          </h1>
          <p className="text-xs text-slate-500 hidden sm:block">
            Hospital Health Education & Promotion Activities
          </p>
        </div>
      </div>

      <div className="flex items-center gap-4">
        {/* User Info */}
        {user ? (
          <div className="flex items-center gap-3">
            <div className="text-right hidden md:block">
              <p className="text-sm font-semibold text-slate-900 leading-none">{user.name}</p>
              <span className="inline-block mt-0.5 rounded-full bg-blue-50 px-2 py-0.5 text-[10px] font-semibold text-blue-700 capitalize border border-blue-100">
                {user.role} • {user.department || 'HPU'}
              </span>
            </div>

            <div className="flex h-9 w-9 items-center justify-center rounded-full bg-blue-600 text-white font-semibold text-sm shadow-xs">
              {user.name ? user.name.charAt(0).toUpperCase() : <UserIcon className="h-4 w-4" />}
            </div>

            <button
              onClick={() => logout()}
              title="Sign Out"
              className="flex h-9 w-9 items-center justify-center rounded-lg text-slate-400 hover:bg-slate-100 hover:text-red-600 transition-colors"
            >
              <LogOut className="h-4 w-4" />
            </button>
          </div>
        ) : (
          <div className="h-8 w-24 bg-slate-200 animate-pulse rounded-md" />
        )}
      </div>
    </header>
  );
}
