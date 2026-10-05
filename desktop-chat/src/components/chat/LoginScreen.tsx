import React, { useState } from 'react';
import { MessageSquare, Lock, Mail, Key, ShieldCheck } from 'lucide-react';
import { loginApi } from '../../services/auth';
import { authStore } from '../../stores/authStore';
import { uiStore } from '../../stores/uiStore';

export const LoginScreen: React.FC = () => {
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [isSubmitting, setIsSubmitting] = useState(false);
  const [errorMsg, setErrorMsg] = useState<string | null>(null);

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!email || !password) {
      setErrorMsg('Please enter your email and account password.');
      return;
    }

    setIsSubmitting(true);
    setErrorMsg(null);

    const res = await loginApi(email, password);
    setIsSubmitting(false);

    if (res.success && res.data?.user) {
      authStore.setState({
        user: res.data.user,
        isAuthenticated: true,
        isLoading: false,
        error: null,
      });
      uiStore.setChatState('OPEN');
    } else {
      setErrorMsg(res.message || 'Invalid credentials. Please check your email and password.');
    }
  };

  return (
    <div className="w-full h-full flex flex-col justify-center items-center bg-slate-900 text-slate-100 p-6 select-none border border-slate-700/50 shadow-2xl rounded-2xl">
      <div className="flex flex-col items-center mb-6 text-center">
        <div className="w-14 h-14 rounded-2xl bg-blue-600 flex items-center justify-center shadow-lg shadow-blue-500/30 mb-3">
          <MessageSquare className="w-7 h-7 text-white" />
        </div>
        <h1 className="text-xl font-bold tracking-tight text-white">PHU Workbench</h1>
        <p className="text-xs text-slate-400 font-medium">Desktop Staff Chat Companion</p>
      </div>

      <form onSubmit={handleSubmit} className="w-full max-w-xs space-y-3.5">
        <div>
          <label className="block text-xs font-semibold text-slate-300 mb-1">Email / Username</label>
          <div className="relative">
            <Mail className="w-4 h-4 absolute left-3 top-3 text-slate-400" />
            <input
              type="email"
              value={email}
              onChange={(e) => setEmail(e.target.value)}
              placeholder="user@hpu.local"
              required
              className="w-full pl-9 pr-3 py-2 bg-slate-800 border border-slate-700 rounded-xl text-sm text-slate-100 placeholder-slate-500 focus:outline-none focus:ring-2 focus:ring-blue-500 transition-all"
            />
          </div>
        </div>

        <div>
          <label className="block text-xs font-semibold text-slate-300 mb-1">Account Password</label>
          <div className="relative">
            <Key className="w-4 h-4 absolute left-3 top-3 text-slate-400" />
            <input
              type="password"
              value={password}
              onChange={(e) => setPassword(e.target.value)}
              placeholder="••••••••"
              required
              className="w-full pl-9 pr-3 py-2 bg-slate-800 border border-slate-700 rounded-xl text-sm text-slate-100 placeholder-slate-500 focus:outline-none focus:ring-2 focus:ring-blue-500 transition-all"
            />
          </div>
        </div>

        {errorMsg && (
          <div className="p-2.5 bg-red-500/10 border border-red-500/30 text-red-400 text-xs rounded-xl flex items-start space-x-2">
            <ShieldCheck className="w-4 h-4 flex-shrink-0 mt-0.5" />
            <span>{errorMsg}</span>
          </div>
        )}

        <button
          type="submit"
          disabled={isSubmitting}
          className="w-full py-2.5 bg-blue-600 hover:bg-blue-500 text-white font-semibold rounded-xl text-sm transition-all shadow-md shadow-blue-600/30 active:scale-[0.98] disabled:opacity-50 disabled:cursor-not-allowed flex items-center justify-center space-x-2"
        >
          <Lock className="w-4 h-4" />
          <span>{isSubmitting ? 'Authenticating...' : 'Sign In to PHU Chat'}</span>
        </button>
      </form>

      <div className="mt-6 text-center text-[11px] text-slate-500">
        Health Promotion Unit • Workplace Chat Companion
      </div>
    </div>
  );
};
