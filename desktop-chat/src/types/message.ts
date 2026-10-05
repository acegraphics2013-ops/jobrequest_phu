export interface Message {
  id: number | string;
  sender_id: number;
  sender_name: string;
  sender_role?: string;
  receiver_id?: number | null;
  channel?: string;
  message: string;
  attachment_url?: string | null;
  attachment_name?: string | null;
  attachment_is_image?: boolean;
  reply_to?: number | null;
  created_at: string;
  isSending?: boolean;
  isFailed?: boolean;
}
