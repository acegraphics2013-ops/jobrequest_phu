import { apiRequest, ApiResponse } from './api';
import { User } from '../types/user';

export async function loginApi(email: string, password: string): Promise<ApiResponse<{ user: User }>> {
  return apiRequest<{ user: User }>('/login.php', {
    method: 'POST',
    body: JSON.stringify({ email, password }),
  });
}

export async function checkSessionApi(): Promise<ApiResponse<{ user: User }>> {
  return apiRequest<{ user: User }>('/me.php', {
    method: 'GET',
  });
}

export async function logoutApi(): Promise<ApiResponse<null>> {
  return apiRequest<null>('/logout.php', {
    method: 'POST',
  });
}

export async function sendHeartbeatApi(): Promise<ApiResponse<any>> {
  return apiRequest<any>('/presence-heartbeat.php', {
    method: 'POST',
  });
}
