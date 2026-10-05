const API_BASE_URL = process.env.NEXT_PUBLIC_API_URL || '/backend-api';

export interface ApiResponse<T = any> {
  success: boolean;
  message: string;
  data: T;
}

let cachedCsrfToken: string | null = null;

export async function getCsrfToken(): Promise<string> {
  if (cachedCsrfToken) {
    return cachedCsrfToken;
  }
  try {
    const res = await fetch(`${API_BASE_URL}/security/csrf.php`, {
      credentials: 'include',
    });
    const json = await res.json();
    if (json.success && json.data?.csrfToken) {
      cachedCsrfToken = json.data.csrfToken;
      return cachedCsrfToken || '';
    }
  } catch (e) {
    console.error('Failed to fetch CSRF token:', e);
  }
  return '';
}

export async function fetchApi<T = any>(
  endpoint: string,
  options: RequestInit = {}
): Promise<ApiResponse<T>> {
  const cleanEndpoint = endpoint.startsWith('/') ? endpoint : `/${endpoint}`;
  const url = endpoint.startsWith('http') ? endpoint : `${API_BASE_URL}${cleanEndpoint}`;

  const headers: Record<string, string> = {
    'Accept': 'application/json',
  };

  if (options.headers) {
    Object.assign(headers, options.headers);
  }

  if (!(options.body instanceof FormData) && !headers['Content-Type']) {
    headers['Content-Type'] = 'application/json';
  }

  // Attach CSRF token for mutating requests
  const method = (options.method || 'GET').toUpperCase();
  if (['POST', 'PUT', 'DELETE', 'PATCH'].includes(method)) {
    const token = await getCsrfToken();
    if (token) {
      headers['X-CSRF-Token'] = token;
    }
  }

  const config: RequestInit = {
    ...options,
    headers,
    credentials: 'include', // Pass PHPSESSID cookie to PHP session backend
  };

  try {
    const response = await fetch(url, config);
    const result = await response.json().catch(() => ({
      success: false,
      message: `Server returned non-JSON response (HTTP ${response.status})`,
      data: null,
    }));

    if (!response.ok && !result.message) {
      throw new Error(`HTTP Error ${response.status}: ${response.statusText}`);
    }

    return result as ApiResponse<T>;
  } catch (error: any) {
    console.error(`API Fetch Error [${endpoint}]:`, error);
    return {
      success: false,
      message: error.message || 'Network error or server unreachable.',
      data: null as any,
    };
  }
}
