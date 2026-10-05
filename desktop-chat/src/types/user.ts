export interface User {
  id: number;
  name: string;
  email: string;
  role: 'admin' | 'supervisor' | 'staff' | 'user' | string;
  department?: string;
  photo_url?: string | null;
  is_online?: boolean;
  last_message?: string;
  last_message_time?: string;
}
