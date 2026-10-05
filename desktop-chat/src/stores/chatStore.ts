import { ActiveConversation, Channel } from '../types/conversation';
import { Message } from '../types/message';
import { User } from '../types/user';

export interface ChatStoreState {
  channels: Channel[];
  teamMembers: User[];
  onlineUserIds: number[];
  unreadCounts: Record<number, number>;
  totalUnread: number;
  activeConversation: ActiveConversation;
  messages: Message[];
  isLoadingMessages: boolean;
  isSending: boolean;
  isConnected: boolean;
  error: string | null;
}

type Listener = (state: ChatStoreState) => void;

class ChatStore {
  private state: ChatStoreState = {
    channels: [
      { key: 'general', name: 'General Channel', desc: 'Team-wide discussion', unread: 0 },
      { key: 'announcements', name: 'Announcements', desc: 'Broadcast updates', unread: 0 }
    ],
    teamMembers: [],
    onlineUserIds: [],
    unreadCounts: {},
    totalUnread: 0,
    activeConversation: { channel: 'general', receiver_id: null, name: 'General Channel' },
    messages: [],
    isLoadingMessages: false,
    isSending: false,
    isConnected: true,
    error: null,
  };

  private listeners: Set<Listener> = new Set();

  getState(): ChatStoreState {
    return this.state;
  }

  setState(partial: Partial<ChatStoreState>) {
    this.state = { ...this.state, ...partial };
    this.notify();
  }

  subscribe(listener: Listener) {
    this.listeners.add(listener);
    return () => this.listeners.delete(listener);
  }

  private notify() {
    this.listeners.forEach((fn) => fn(this.state));
  }
}

export const chatStore = new ChatStore();
