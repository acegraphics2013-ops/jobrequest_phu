import React, { useEffect, useState, useRef } from 'react';
import { LoginScreen } from './components/chat/LoginScreen';
import { ChatHead } from './components/chat/ChatHead';
import { ChatWindow } from './components/chat/ChatWindow';
import { authStore, AuthState } from './stores/authStore';
import { uiStore, UIState } from './stores/uiStore';
import { chatStore, ChatStoreState } from './stores/chatStore';
import { checkSessionApi, sendHeartbeatApi } from './services/auth';
import { fetchConversationsApi, markReadApi } from './services/conversations';
import { fetchMessagesApi, sendMessageApi } from './services/messages';
import { ActiveConversation } from './types/conversation';
import { Message } from './types/message';

export const App: React.FC = () => {
  const [authState, setAuthState] = useState<AuthState>(authStore.getState());
  const [uiState, setUiState] = useState<UIState>(uiStore.getState());
  const [chatData, setChatData] = useState<ChatStoreState>(chatStore.getState());

  const pollTimerRef = useRef<any>(null);
  const heartbeatTimerRef = useRef<any>(null);
  const prevTotalUnreadRef = useRef<number>(0);

  // Subscribe to Stores
  useEffect(() => {
    const unsubAuth = authStore.subscribe(setAuthState);
    const unsubUI = uiStore.subscribe(setUiState);
    const unsubChat = chatStore.subscribe(setChatData);

    // Check initial authentication
    checkSessionApi().then((res) => {
      if (res.success && res.data?.user) {
        authStore.setState({
          user: res.data.user,
          isAuthenticated: true,
          isLoading: false,
        });
        uiStore.setChatState('OPEN');
      } else {
        authStore.setState({
          user: null,
          isAuthenticated: false,
          isLoading: false,
        });
        uiStore.setChatState('LOGGED_OUT');
      }
    });

    return () => {
      unsubAuth();
      unsubUI();
      unsubChat();
    };
  }, []);

  // Sync Data Engine
  const loadConversations = async () => {
    if (!authStore.getState().isAuthenticated) return;
    const res = await fetchConversationsApi();
    if (res.success && res.data) {
      chatStore.setState({
        channels: res.data.channels,
        teamMembers: res.data.team_members,
        onlineUserIds: res.data.online_user_ids,
        unreadCounts: res.data.unread_counts,
        totalUnread: res.data.total_unread,
        isConnected: true,
      });

      // Windows Notification check
      if (
        prevTotalUnreadRef.current >= 0 &&
        res.data.total_unread > prevTotalUnreadRef.current &&
        'Notification' in window &&
        Notification.permission === 'granted'
      ) {
        new Notification('PHU Workbench Chat', {
          body: 'New PHU Chat message received',
          icon: '/favicon.ico',
        });
      }
      prevTotalUnreadRef.current = res.data.total_unread;
    }
  };

  const loadMessages = async () => {
    if (!authStore.getState().isAuthenticated) return;
    const activeConv = chatStore.getState().activeConversation;
    const res = await fetchMessagesApi(activeConv.channel, activeConv.receiver_id);
    if (res.success && res.data) {
      chatStore.setState({
        messages: res.data.messages,
        isLoadingMessages: false,
      });
    }
  };

  // Polling Loop
  useEffect(() => {
    if (!authState.isAuthenticated) return;

    loadConversations();
    loadMessages();

    pollTimerRef.current = setInterval(() => {
      loadConversations();
      if (uiStore.getState().chatState === 'OPEN') {
        loadMessages();
      }
    }, 2500);

    heartbeatTimerRef.current = setInterval(() => {
      sendHeartbeatApi();
    }, 45000);

    // Request Notification Permissions
    if ('Notification' in window && Notification.permission === 'default') {
      Notification.requestPermission();
    }

    return () => {
      if (pollTimerRef.current) clearInterval(pollTimerRef.current);
      if (heartbeatTimerRef.current) clearInterval(heartbeatTimerRef.current);
    };
  }, [authState.isAuthenticated]);

  // Reset Auto Lock on User Interactions
  useEffect(() => {
    const handleUserInteraction = () => {
      uiStore.resetAutoLockTimer();
    };

    const events = ['mousemove', 'mousedown', 'keydown', 'scroll', 'touchstart'];
    events.forEach((evt) => window.addEventListener(evt, handleUserInteraction, { passive: true }));

    return () => {
      events.forEach((evt) => window.removeEventListener(evt, handleUserInteraction));
    };
  }, []);

  const handleSelectConversation = (conv: ActiveConversation) => {
    chatStore.setState({
      activeConversation: conv,
      isLoadingMessages: true,
    });
    if (conv.receiver_id) {
      markReadApi(conv.receiver_id);
    }
    loadMessages();
    uiStore.resetAutoLockTimer();
  };

  const handleSendMessage = async (text: string, file?: File | null) => {
    const activeConv = chatData.activeConversation;
    const currentUser = authState.user;
    if (!currentUser) return;

    chatStore.setState({ isSending: true });

    // Optimistic Rendering
    const tempMsg: Message = {
      id: `temp_${Date.now()}`,
      sender_id: currentUser.id,
      sender_name: currentUser.name,
      sender_role: currentUser.role,
      receiver_id: activeConv.receiver_id,
      channel: activeConv.channel || 'general',
      message: text,
      created_at: new Date().toISOString(),
      isSending: true,
    };

    chatStore.setState({
      messages: [...chatData.messages, tempMsg],
    });

    const res = await sendMessageApi(text, activeConv.channel, activeConv.receiver_id, file);
    chatStore.setState({ isSending: false });

    if (res.success) {
      loadMessages();
      loadConversations();
    }
    uiStore.resetAutoLockTimer();
  };

  if (authState.isLoading) {
    return (
      <div className="w-screen h-screen flex items-center justify-center bg-slate-950 text-slate-400 text-xs font-semibold select-none">
        Initializing PHU Desktop Chat Companion...
      </div>
    );
  }

  if (!authState.isAuthenticated || uiState.chatState === 'LOGGED_OUT') {
    return (
      <div className="w-screen h-screen p-3 flex items-center justify-center bg-slate-950">
        <LoginScreen />
      </div>
    );
  }

  return (
    <div className="w-screen h-screen bg-transparent overflow-hidden select-none relative">
      {/* Floating Chat Head Launcher (When Minimized or Head-Only) */}
      {(uiState.chatState === 'HEAD_ONLY' || uiState.chatState === 'MINIMIZED') && (
        <div className="absolute right-4 bottom-4 z-50">
          <ChatHead
            unreadCount={chatData.totalUnread}
            isLocked={false}
            onClick={() => uiStore.setChatState('OPEN')}
          />
        </div>
      )}

      {/* Expanded Floating Chat Window (When OPEN or LOCKED) */}
      {(uiState.chatState === 'OPEN' || uiState.chatState === 'LOCKED') && authState.user && (
        <div className="w-full h-full p-2">
          <ChatWindow
            currentUser={authState.user}
            channels={chatData.channels}
            teamMembers={chatData.teamMembers}
            unreadCounts={chatData.unreadCounts}
            activeConversation={chatData.activeConversation}
            messages={chatData.messages}
            isLoadingMessages={chatData.isLoadingMessages}
            isSending={chatData.isSending}
            isLocked={uiState.chatState === 'LOCKED'}
            showIdleWarning={uiState.showIdleWarning}
            onSelectConversation={handleSelectConversation}
            onSendMessage={handleSendMessage}
            onLock={() => uiStore.setChatState('LOCKED')}
            onMinimize={() => uiStore.setChatState('MINIMIZED')}
            onClose={() => uiStore.setChatState('MINIMIZED')}
            onUnlockSuccess={() => uiStore.setChatState('OPEN')}
          />
        </div>
      )}
    </div>
  );
};
