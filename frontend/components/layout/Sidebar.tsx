'use client';

import React, { useState, useEffect } from 'react';
import Link from 'next/link';
import { usePathname } from 'next/navigation';
import { useAuth } from '@/lib/auth-context';
import { useSidebar } from '@/components/layout/SidebarContext';
import {
  LayoutDashboard,
  FileText,
  ClipboardList,
  ClipboardCheck,
  ShieldCheck,
  FileSpreadsheet,
  Kanban,
  FolderKanban,
  Calendar,
  CalendarX,
  GraduationCap,
  UserCheck,
  FolderOpen,
  Bell,
  MessageSquare,
  UserPlus,
  Users,
  Building2,
  Sliders,
  Database,
  FolderArchive,
  BarChart3,
  History,
  ChevronLeft,
  ChevronRight,
  MoreVertical,
  User as UserIcon,
  Settings as SettingsIcon,
  LogOut,
  Activity,
} from 'lucide-react';

interface NavItem {
  title: string;
  href: string;
  icon: React.ElementType;
  roles?: string[];
  badge?: number | string;
}

interface NavSection {
  title: string;
  items: NavItem[];
}

export const Sidebar: React.FC = () => {
  const pathname = usePathname();
  const { user, logout } = useAuth();
  const { collapsed, toggleCollapse, mobileOpen, setMobileOpen } = useSidebar();
  const [userMenuOpen, setUserMenuOpen] = useState(false);
  const [unreadCount, setUnreadCount] = useState<number>(3); // Demo unread count

  const userRole = user?.role || 'staff';

  // Helper to extract initials like "AC" from "ACE RAZ CABUGA"
  const getUserInitials = (name?: string) => {
    if (!name) return 'AC';
    const parts = name.trim().split(/\s+/);
    if (parts.length >= 2) {
      return (parts[0][0] + parts[parts.length - 1][0]).toUpperCase();
    }
    return name.slice(0, 2).toUpperCase();
  };

  // Close mobile drawer on Escape key
  useEffect(() => {
    const handleKeyDown = (e: KeyboardEvent) => {
      if (e.key === 'Escape') {
        setMobileOpen(false);
        setUserMenuOpen(false);
      }
    };
    window.addEventListener('keydown', handleKeyDown);
    return () => window.removeEventListener('keydown', handleKeyDown);
  }, [setMobileOpen]);

  // Navigation menu grouped into strict sections
  const sections: NavSection[] = [
    {
      title: 'MAIN',
      items: [
        { title: 'Dashboard', href: '/dashboard', icon: LayoutDashboard },
      ],
    },
    {
      title: 'REQUESTS',
      items: [
        { title: 'Job Requests', href: '/requests', icon: FileText },
        { title: 'My Requests', href: '/requests/my-requests', icon: ClipboardList, roles: ['user', 'staff', 'supervisor'] },
        { title: 'Request Review', href: '/requests/review', icon: ClipboardCheck, roles: ['staff', 'supervisor', 'admin'] },
        { title: 'Approval Workflow', href: '/requests/approvals', icon: ShieldCheck, roles: ['supervisor', 'admin'] },
        { title: 'Publication Requests', href: '/requests/publication', icon: FileSpreadsheet, roles: ['staff', 'supervisor', 'admin'] },
      ],
    },
    {
      title: 'WORKFLOW',
      items: [
        { title: 'Kanban Board', href: '/kanban', icon: Kanban, roles: ['admin', 'staff', 'supervisor'] },
        { title: 'Project Monitoring', href: '/projects', icon: FolderKanban },
        { title: 'Calendar of Activities', href: '/calendar', icon: Calendar },
        { title: 'Blocked Dates', href: '/blocked-dates', icon: CalendarX, roles: ['staff', 'supervisor', 'admin'] },
        { title: 'Ward Health Education', href: '/health-education', icon: GraduationCap },
        { title: 'My Assigned Jobs', href: '/my-assigned-jobs', icon: UserCheck, roles: ['staff', 'supervisor', 'admin'] },
        { title: 'IEC Materials', href: '/iec-materials', icon: FolderOpen },
      ],
    },
    {
      title: 'COMMUNICATION',
      items: [
        { title: 'Notifications', href: '/notifications', icon: Bell, badge: unreadCount },
        { title: 'Chat Center', href: '/chat', icon: MessageSquare },
      ],
    },
    {
      title: 'MANAGEMENT',
      items: [
        { title: 'Account Requests', href: '/account-requests', icon: UserPlus, roles: ['admin', 'supervisor', 'staff'] },
        { title: 'User Management', href: '/users', icon: Users, roles: ['admin', 'staff'] },
        { title: 'Department Management', href: '/departments', icon: Building2, roles: ['admin'] },
        { title: 'Module Access', href: '/module-access', icon: Sliders, roles: ['admin', 'staff'] },
        { title: 'Database Backup', href: '/backup', icon: Database, roles: ['admin'] },
        { title: 'Upload Location', href: '/upload-settings', icon: FolderArchive, roles: ['admin'] },
      ],
    },
    {
      title: 'REPORTS',
      items: [
        { title: 'Reports', href: '/reports', icon: BarChart3, roles: ['admin', 'staff', 'supervisor'] },
        { title: 'Activity Logs', href: '/activity-logs', icon: History, roles: ['admin', 'staff', 'supervisor'] },
      ],
    },
  ];

  // Filter sections and items based on role permissions
  const filteredSections = sections
    .map((sec) => ({
      ...sec,
      items: sec.items.filter((item) => !item.roles || item.roles.includes(userRole)),
    }))
    .filter((sec) => sec.items.length > 0);

  return (
    <>
      {/* Mobile Drawer Dark Backdrop Overlay */}
      {mobileOpen && (
        <div
          onClick={() => setMobileOpen(false)}
          className="fixed inset-0 bg-slate-950/60 backdrop-blur-xs z-30 lg:hidden transition-opacity duration-200"
        />
      )}

      {/* Main Sidebar Component */}
      <aside
        className={`fixed left-0 top-0 h-screen flex flex-col overflow-hidden bg-slate-950 border-r border-slate-800 z-40 transition-[width,transform] duration-200 ease-out select-none ${
          collapsed ? 'w-16' : 'w-[220px]'
        } ${mobileOpen ? 'translate-x-0 w-[220px]' : '-translate-x-full lg:translate-x-0'}`}
      >
        {/* HEADER SECTION */}
        <div className="shrink-0 h-[64px] border-b border-slate-800 px-3 flex items-center justify-between bg-slate-950">
          <Link href="/dashboard" className="flex items-center gap-2.5 min-w-0 overflow-hidden">
            <div className="w-8 h-8 rounded-lg bg-blue-600 flex items-center justify-center text-white font-bold shrink-0 shadow-sm">
              <Activity className="w-4 h-4" />
            </div>
            {!collapsed && (
              <div className="min-w-0 flex flex-col">
                <span className="text-sm font-semibold text-white truncate leading-tight">
                  PHU Workbench
                </span>
                <span className="text-[11px] text-slate-400 truncate">
                  Job Request System
                </span>
              </div>
            )}
          </Link>

          <button
            type="button"
            onClick={toggleCollapse}
            className="hidden lg:flex w-8 h-8 items-center justify-center rounded-lg text-slate-400 hover:bg-slate-800 hover:text-white transition-colors shrink-0"
            title={collapsed ? 'Expand sidebar' : 'Collapse sidebar'}
            aria-label="Toggle Sidebar"
          >
            {collapsed ? <ChevronRight className="w-4 h-4" /> : <ChevronLeft className="w-4 h-4" />}
          </button>
        </div>

        {/* SCROLLABLE NAVIGATION SECTION */}
        <div className="flex-1 min-h-0 overflow-y-auto overflow-x-hidden p-2 space-y-3 scrollbar-thin scrollbar-thumb-slate-800 scrollbar-track-transparent">
          {filteredSections.map((section) => (
            <div key={section.title}>
              {!collapsed && (
                <h2 className="text-[10px] font-semibold uppercase tracking-wider text-slate-500 px-3 mt-3 mb-1">
                  {section.title}
                </h2>
              )}
              <nav className="space-y-1">
                {section.items.map((item) => {
                  const Icon = item.icon;
                  const isActive =
                    pathname === item.href ||
                    (item.href !== '/dashboard' && pathname.startsWith(item.href));

                  return (
                    <Link
                      key={item.href}
                      href={item.href}
                      onClick={() => setMobileOpen(false)}
                      title={collapsed ? item.title : undefined}
                      className={`flex h-9 items-center gap-3 rounded-lg px-3 text-[13px] font-medium transition-colors relative group ${
                        isActive
                          ? 'bg-blue-600/15 text-blue-400 font-semibold'
                          : 'text-slate-400 hover:bg-slate-800 hover:text-slate-100'
                      }`}
                    >
                      {/* Left Active Bar Indicator */}
                      {isActive && (
                        <span className="absolute left-0 top-2 bottom-2 w-[3px] rounded-r bg-blue-500" />
                      )}

                      <Icon className="w-4 h-4 shrink-0" />

                      {!collapsed ? (
                        <>
                          <span className="truncate whitespace-nowrap">{item.title}</span>
                          {item.badge ? (
                            <span className="min-w-[18px] h-[18px] px-1 rounded-full bg-red-500 text-white text-[10px] font-bold inline-flex items-center justify-center ml-auto shrink-0">
                              {item.badge}
                            </span>
                          ) : null}
                        </>
                      ) : (
                        item.badge ? (
                          <span className="absolute -top-1 -right-1 min-w-[14px] h-[14px] px-0.5 rounded-full bg-red-500 text-white text-[8px] font-bold flex items-center justify-center">
                            {item.badge}
                          </span>
                        ) : null
                      )}

                      {/* Floating Tooltip in Collapsed Mode */}
                      {collapsed && (
                        <div className="absolute left-full top-1/2 -translate-y-1/2 ml-2.5 px-2.5 py-1 bg-slate-900 text-white text-xs font-medium rounded-md shadow-xl border border-slate-800 opacity-0 pointer-events-none group-hover:opacity-100 transition-opacity z-50 whitespace-nowrap">
                          {item.title}
                        </div>
                      )}
                    </Link>
                  );
                })}
              </nav>
            </div>
          ))}
        </div>

        {/* FIXED USER FOOTER SECTION */}
        <div className="shrink-0 border-t border-slate-800 p-3 bg-slate-950 relative">
          {/* Floating User Dropdown Menu */}
          {userMenuOpen && (
            <div
              className={`absolute bottom-full mb-2 bg-slate-900 border border-slate-800 rounded-lg p-1 shadow-xl z-50 ${
                collapsed ? 'left-16 w-44' : 'left-3 right-3'
              }`}
            >
              <Link
                href="/profile"
                onClick={() => setUserMenuOpen(false)}
                className="flex items-center gap-2 px-3 py-2 text-xs font-medium text-slate-300 hover:bg-slate-800 hover:text-white rounded-md transition-colors"
              >
                <UserIcon className="w-3.5 h-3.5" />
                <span>Profile</span>
              </Link>
              <Link
                href="/settings"
                onClick={() => setUserMenuOpen(false)}
                className="flex items-center gap-2 px-3 py-2 text-xs font-medium text-slate-300 hover:bg-slate-800 hover:text-white rounded-md transition-colors"
              >
                <SettingsIcon className="w-3.5 h-3.5" />
                <span>Settings</span>
              </Link>
              <div className="border-t border-slate-800 my-1" />
              <button
                type="button"
                onClick={() => {
                  setUserMenuOpen(false);
                  logout();
                }}
                className="w-full flex items-center gap-2 px-3 py-2 text-xs font-medium text-red-400 hover:bg-red-500/10 hover:text-red-300 rounded-md transition-colors text-left"
              >
                <LogOut className="w-3.5 h-3.5" />
                <span>Logout</span>
              </button>
            </div>
          )}

          {/* User Row */}
          <div className="flex items-center gap-2.5 min-w-0">
            <div
              onClick={() => setUserMenuOpen(!userMenuOpen)}
              className="w-8 h-8 rounded-full bg-blue-600 text-white text-xs font-bold shrink-0 flex items-center justify-center cursor-pointer hover:ring-2 hover:ring-blue-500 transition-all"
              title={user?.name || 'ACE RAZ CABUGA'}
            >
              {getUserInitials(user?.name || 'ACE RAZ CABUGA')}
            </div>

            {!collapsed && (
              <>
                <div className="min-w-0 flex-1">
                  <p className="text-xs font-semibold text-white truncate leading-tight">
                    {user?.name || 'ACE RAZ CABUGA'}
                  </p>
                  <p className="text-[10px] text-slate-400 truncate mt-0.5">
                    {user?.role ? `${user.role.charAt(0).toUpperCase() + user.role.slice(1)} Staff` : 'HPU Staff'}
                  </p>
                </div>
                <button
                  type="button"
                  onClick={() => setUserMenuOpen(!userMenuOpen)}
                  className="p-1 rounded-lg text-slate-400 hover:text-white hover:bg-slate-800 transition-colors ml-auto shrink-0"
                  title="User Menu"
                >
                  <MoreVertical className="w-4 h-4" />
                </button>
              </>
            )}
          </div>
        </div>
      </aside>
    </>
  );
};

// Helper component for page container margin responsiveness
export const SidebarLayout: React.FC<{ children: React.ReactNode }> = ({ children }) => {
  const { collapsed } = useSidebar();
  return (
    <div
      className={`min-h-screen bg-slate-50 transition-[margin-left] duration-200 ease-out ${
        collapsed ? 'lg:ml-16' : 'lg:ml-[220px]'
      }`}
    >
      {children}
    </div>
  );
};
