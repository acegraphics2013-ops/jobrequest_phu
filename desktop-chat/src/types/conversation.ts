import { User } from './user';

export interface Channel {
  key: string;
  name: string;
  desc?: string;
  unread?: number;
}

export interface ActiveConversation {
  channel: string | null;
  receiver_id: number | null;
  name: string;
}

export interface ConversationsData {
  channels: Channel[];
  team_members: User[];
  online_user_ids: number[];
  unread_counts: Record<number, number>;
  total_unread: number;
}
