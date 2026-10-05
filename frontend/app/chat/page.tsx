'use client';

import React, { useEffect, useState } from 'react';
import { useAuth } from '@/lib/auth-context';
import { fetchApi } from '@/lib/api';
import { Sidebar } from '@/components/layout/Sidebar';
import { Navbar } from '@/components/layout/Navbar';
import { JobRequest } from '@/types';
import { MessageSquare, Send, ShieldLock, User, Search, Paperclip, CheckCheck } from 'lucide-react';

interface ChatMessage {
  id: number;
  request_id: number;
  sender_id: number;
  sender_name: string;
  sender_role: string;
  message: string;
  is_internal: number;
  created_at: string;
  is_mine: boolean;
}

export default function ChatCenterPage() {
  const { user } = useAuth();
  const [requests, setRequests] = useState<JobRequest[]>([]);
  const [selectedRequestId, setSelectedRequestId] = useState<number | null>(null);
  const [messages, setMessages] = useState<ChatMessage[]>([]);
  const [newMessage, setNewMessage] = useState('');
  const [isInternal, setIsInternal] = useState(false);
  const [loadingRequests, setLoadingRequests] = useState(true);
  const [loadingMessages, setLoadingMessages] = useState(false);
  const [sending, setSending] = useState(false);

  // 1. Fetch available requests for chat list
  useEffect(() => {
    async function loadRequests() {
      const res = await fetchApi<{ requests: JobRequest[] }>('/requests/index.php?limit=50');
      if (res.success && res.data?.requests) {
        setRequests(res.data.requests);
        if (res.data.requests.length > 0) {
          setSelectedRequestId(res.data.requests[0].id);
        }
      }
      setLoadingRequests(false);
    }
    if (user) {
      loadRequests();
    }
  }, [user]);

  // 2. Fetch chat messages when active request changes
  const loadMessages = async (reqId: number) => {
    setLoadingMessages(true);
    const res = await fetchApi<{ messages: ChatMessage[] }>(`/chat/fetch.php?request_id=${reqId}`);
    if (res.success && res.data?.messages) {
      setMessages(res.data.messages);
    } else {
      setMessages([]);
    }
    setLoadingMessages(false);
  };

  useEffect(() => {
    if (selectedRequestId) {
      loadMessages(selectedRequestId);
    }
  }, [selectedRequestId]);

  const handleSendMessage = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!newMessage.trim() || !selectedRequestId || sending) return;

    setSending(true);
    const res = await fetchApi('/chat/send.php', {
      method: 'POST',
      body: JSON.stringify({
        request_id: selectedRequestId,
        message: newMessage.trim(),
        is_internal: isInternal ? 1 : 0,
      }),
    });

    if (res.success) {
      setNewMessage('');
      loadMessages(selectedRequestId);
    }
    setSending(false);
  };

  const isStaffRole = ['staff', 'supervisor', 'admin'].includes(user?.role || '');
  const activeRequest = requests.find((r) => r.id === selectedRequestId);

  return (
    <div className="flex min-h-screen bg-slate-50">
      <Sidebar />
      <div className="flex-1 flex flex-col min-w-0">
        <Navbar />

        <main className="p-6 flex-1 flex flex-col max-w-7xl mx-auto w-full">
          <div className="mb-4">
            <h1 className="text-xl font-bold text-slate-900 tracking-tight">Chat Center</h1>
            <p className="text-xs text-slate-500">Real-time communication for job request coordination</p>
          </div>

          {/* Chat Container */}
          <div className="bg-white rounded-2xl border border-slate-200 shadow-xs flex flex-col md:flex-row flex-1 overflow-hidden min-h-[600px]">
            {/* Left Column: Request List */}
            <div className="w-full md:w-80 border-r border-slate-200 bg-slate-50/50 flex flex-col">
              <div className="p-3 border-b border-slate-200">
                <div className="relative">
                  <Search className="w-3.5 h-3.5 absolute left-3 top-2.5 text-slate-400" />
                  <input
                    type="text"
                    placeholder="Search requests..."
                    className="w-full bg-white border border-slate-200 text-xs rounded-xl pl-9 pr-3 py-1.5 focus:outline-none focus:border-teal-500"
                  />
                </div>
              </div>

              <div className="flex-1 overflow-y-auto divide-y divide-slate-100">
                {loadingRequests ? (
                  <div className="p-4 text-center text-xs text-slate-400">Loading requests...</div>
                ) : requests.length === 0 ? (
                  <div className="p-4 text-center text-xs text-slate-400">No active job requests.</div>
                ) : (
                  requests.map((req) => (
                    <button
                      key={req.id}
                      onClick={() => setSelectedRequestId(req.id)}
                      className={`w-full p-3.5 text-left flex items-start gap-3 transition-colors ${
                        selectedRequestId === req.id
                          ? 'bg-teal-50/80 border-l-4 border-teal-600'
                          : 'hover:bg-slate-100/70'
                      }`}
                    >
                      <div className="w-8 h-8 rounded-lg bg-teal-100 text-teal-800 flex items-center justify-center font-bold text-xs shrink-0 mt-0.5">
                        <MessageSquare className="w-4 h-4" />
                      </div>
                      <div className="overflow-hidden flex-1">
                        <div className="flex items-center justify-between">
                          <span className="font-semibold text-xs text-slate-900 truncate">
                            {req.control_number}
                          </span>
                          <span className="text-[10px] text-slate-400">
                            {new Date(req.created_at).toLocaleDateString()}
                          </span>
                        </div>
                        <p className="text-xs text-slate-600 truncate mt-0.5">{req.request_type}</p>
                        <p className="text-[11px] text-slate-400 truncate">{req.requester_name}</p>
                      </div>
                    </button>
                  ))
                )}
              </div>
            </div>

            {/* Right Column: Chat Box */}
            <div className="flex-1 flex flex-col bg-white">
              {activeRequest ? (
                <>
                  {/* Chat Box Header */}
                  <div className="p-4 border-b border-slate-200 flex items-center justify-between bg-slate-50">
                    <div>
                      <h3 className="font-bold text-sm text-slate-900">
                        {activeRequest.control_number} — {activeRequest.request_type}
                      </h3>
                      <p className="text-xs text-slate-500">
                        Requester: {activeRequest.requester_name} ({activeRequest.department})
                      </p>
                    </div>
                  </div>

                  {/* Messages Feed */}
                  <div className="flex-1 p-4 space-y-3 overflow-y-auto max-h-[440px]">
                    {loadingMessages ? (
                      <div className="p-8 text-center text-xs text-slate-400">Loading messages...</div>
                    ) : messages.length === 0 ? (
                      <div className="p-8 text-center text-xs text-slate-400">
                        No messages yet in this conversation. Start the discussion below.
                      </div>
                    ) : (
                      messages.map((msg) => (
                        <div
                          key={msg.id}
                          className={`flex flex-col ${msg.is_mine ? 'items-end' : 'items-start'}`}
                        >
                          <div className="flex items-center gap-1.5 mb-1 text-[11px] text-slate-400">
                            <span className="font-medium text-slate-700">{msg.sender_name}</span>
                            <span className="capitalize text-[10px] px-1 py-0.2 rounded bg-slate-100 border text-slate-500">
                              {msg.sender_role}
                            </span>
                            <span>•</span>
                            <span>{new Date(msg.created_at).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })}</span>
                          </div>
                          <div
                            className={`p-3 rounded-2xl max-w-lg text-xs leading-relaxed ${
                              msg.is_internal
                                ? 'bg-amber-50 text-amber-900 border border-amber-200'
                                : msg.is_mine
                                ? 'bg-teal-600 text-white shadow-xs'
                                : 'bg-slate-100 text-slate-800 border border-slate-200'
                            }`}
                          >
                            {msg.is_internal === 1 && (
                              <div className="flex items-center gap-1 text-[10px] font-semibold text-amber-700 mb-1">
                                <ShieldLock className="w-3 h-3" />
                                <span>Internal Note</span>
                              </div>
                            )}
                            <p className="whitespace-pre-wrap">{msg.message}</p>
                          </div>
                        </div>
                      ))
                    )}
                  </div>

                  {/* Chat Input Form */}
                  <form onSubmit={handleSendMessage} className="p-4 border-t border-slate-200 bg-slate-50 space-y-2">
                    {isStaffRole && (
                      <div className="flex items-center gap-2">
                        <label className="inline-flex items-center gap-1.5 text-xs text-amber-700 font-medium cursor-pointer">
                          <input
                            type="checkbox"
                            checked={isInternal}
                            onChange={(e) => setIsInternal(e.target.checked)}
                            className="rounded border-amber-300 text-amber-600 focus:ring-amber-500"
                          />
                          <ShieldLock className="w-3.5 h-3.5" />
                          <span>Send as Internal Staff Note</span>
                        </label>
                      </div>
                    )}
                    <div className="flex items-center gap-2">
                      <input
                        type="text"
                        value={newMessage}
                        onChange={(e) => setNewMessage(e.target.value)}
                        placeholder="Type a message..."
                        className="flex-1 bg-white border border-slate-200 text-xs rounded-xl px-4 py-2.5 focus:outline-none focus:border-teal-500"
                      />
                      <button
                        type="submit"
                        disabled={sending || !newMessage.trim()}
                        className="bg-teal-600 hover:bg-teal-500 disabled:opacity-50 text-white p-2.5 rounded-xl transition-all shadow-xs"
                      >
                        <Send className="w-4 h-4" />
                      </button>
                    </div>
                  </form>
                </>
              ) : (
                <div className="flex-1 flex items-center justify-center text-slate-400 text-xs p-8">
                  Select a job request from the list to view its conversation.
                </div>
              )}
            </div>
          </div>
        </main>
      </div>
    </div>
  );
}
