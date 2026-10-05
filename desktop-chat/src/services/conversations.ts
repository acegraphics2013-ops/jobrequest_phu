import { apiRequest, ApiResponse } from './api';
import { ConversationsData } from '../types/conversation';

export async function fetchConversationsApi(): Promise<ApiResponse<ConversationsData>> {
  return apiRequest<ConversationsData>('/conversations.php', {
    method: 'GET',
  });
}

export async function markReadApi(senderId: number): Promise<ApiResponse<null>> {
  return apiRequest<null>('/mark-read.php', {
    method: 'POST',
    body: JSON.stringify({ sender_id: senderId }),
  });
}
