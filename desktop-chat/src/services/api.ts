/// <reference types="vite/client" />
export const API_BASE_URL = import.meta.env.VITE_API_BASE_URL || 'http://localhost/jobrequest_phu/api/desktop-chat';

export interface ApiResponse<T = any> {
  success: boolean;
  data: T;
  message: string | null;
}

export async function apiRequest<T = any>(
  endpoint: string,
  options: RequestInit = {}
): Promise<ApiResponse<T>> {
  const url = `${API_BASE_URL}${endpoint.startsWith('/') ? endpoint : '/' + endpoint}`;
  
  const defaultHeaders: Record<string, string> = {
    'Accept': 'application/json',
  };

  if (!(options.body instanceof FormData)) {
    defaultHeaders['Content-Type'] = 'application/json';
  }

  const mergedOptions: RequestInit = {
    ...options,
    credentials: 'include',
    headers: {
      ...defaultHeaders,
      ...options.headers,
    },
  };

  try {
    const res = await fetch(url, mergedOptions);
    const text = await res.text();
    let json: ApiResponse<T>;

    try {
      json = JSON.parse(text);
    } catch (e) {
      throw new Error(`Invalid server response: ${text.substring(0, 100)}`);
    }

    if (!res.ok && json.success !== false) {
      json.success = false;
      json.message = json.message || `HTTP ${res.status} Error`;
    }

    return json;
  } catch (err: any) {
    return {
      success: false,
      data: null as any,
      message: err.message || 'Network communication error. Unable to reach PHU Chat backend.',
    };
  }
}
