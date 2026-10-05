export type UserRole = 'admin' | 'staff' | 'supervisor' | 'user';

export interface User {
  id: number;
  name: string;
  email: string;
  role: UserRole;
  department: string;
  avatar?: string | null;
}

export interface DashboardStats {
  total: number;
  pending: number;
  in_progress: number;
  completed: number;
  approved: number;
  rejected: number;
}

export interface JobRequest {
  id: number;
  control_number: string;
  requester_name: string;
  department: string;
  request_type: string;
  status: 'Pending' | 'In Progress' | 'Completed' | 'Approved' | 'Rejected' | 'Cancelled';
  priority: 'Normal' | 'High' | 'Urgent';
  hpu_status?: string;
  supervisor_status?: string;
  assigned_to?: string;
  created_at: string;
}

export interface Pagination {
  total: number;
  page: number;
  limit: number;
  total_pages: number;
}
