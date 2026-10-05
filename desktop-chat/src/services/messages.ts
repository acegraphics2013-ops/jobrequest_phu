import { apiRequest, ApiResponse } from './api';
import { Message } from '../types/message';

export async function fetchMessagesApi(
  channel: string | null,
  receiverId: number | null,
  afterId?: number,
  beforeId?: number
): Promise<ApiResponse<{ messages: Message[] }>> {
  const params = new URLSearchParams();
  if (receiverId) {
    params.append('receiver_id', receiverId.toString());
  } else {
    params.append('channel', channel || 'general');
  }
  if (afterId) params.append('after_id', afterId.toString());
  if (beforeId) params.append('before_id', beforeId.toString());

  return apiRequest<{ messages: Message[] }>(`/messages.php?${params.toString()}`, {
    method: 'GET',
  });
}

export async function sendMessageApi(
  message: string,
  channel: string | null,
  receiverId: number | null,
  file?: File | null
): Promise<ApiResponse<{ message: Message }>> {
  const formData = new FormData();
  formData.append('message', message);
  if (receiverId) {
    formData.append('receiver_id', receiverId.toString());
  } else {
    formData.append('channel', channel || 'general');
  }
  if (file) {
    formData.append('attachment', file);
  }

  return apiRequest<{ message: Message }>('/send-message.php', {
    method: 'POST',
    body: formData,
  });
}
