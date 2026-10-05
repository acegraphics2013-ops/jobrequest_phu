export type ChatUIState = 'LOGGED_OUT' | 'HEAD_ONLY' | 'OPEN' | 'MINIMIZED' | 'LOCKED' | 'OFFLINE';

export interface UIState {
  chatState: ChatUIState;
  isAlwaysOnTop: boolean;
  autoLockEnabled: boolean;
  autoLockMinutes: number;
  showIdleWarning: boolean;
  isDragging: boolean;
  headPosition: { x: number; y: number };
  windowSize: { width: number; height: number };
}

type Listener = (state: UIState) => void;

const AUTO_LOCK_KEY = 'phu_chat_autolock_mins';
const DEFAULT_AUTO_LOCK = 2;

class UIStore {
  private state: UIState = {
    chatState: 'LOGGED_OUT',
    isAlwaysOnTop: true,
    autoLockEnabled: true,
    autoLockMinutes: parseInt(localStorage.getItem(AUTO_LOCK_KEY) || `${DEFAULT_AUTO_LOCK}`, 10),
    showIdleWarning: false,
    isDragging: false,
    headPosition: { x: window.innerWidth - 80, y: window.innerHeight - 100 },
    windowSize: { width: 440, height: 600 },
  };

  private listeners: Set<Listener> = new Set();
  private lockTimer: any = null;
  private warningTimer: any = null;

  getState(): UIState {
    return this.state;
  }

  setState(partial: Partial<UIState>) {
    this.state = { ...this.state, ...partial };
    this.notify();
  }

  subscribe(listener: Listener) {
    this.listeners.add(listener);
    return () => this.listeners.delete(listener);
  }

  setChatState(newState: ChatUIState) {
    this.setState({ chatState: newState });
    if (newState === 'OPEN') {
      this.resetAutoLockTimer();
    } else {
      this.clearAutoLockTimer();
    }
  }

  // Resettable 2-minute auto lock timer engine
  resetAutoLockTimer() {
    this.clearAutoLockTimer();
    if (!this.state.autoLockEnabled || this.state.chatState !== 'OPEN') return;

    const totalMs = this.state.autoLockMinutes * 60 * 1000;
    const warningMs = Math.max(0, totalMs - 15000); // 15 seconds warning

    if (warningMs > 0) {
      this.warningTimer = setTimeout(() => {
        if (this.state.chatState === 'OPEN') {
          this.setState({ showIdleWarning: true });
        }
      }, warningMs);
    }

    this.lockTimer = setTimeout(() => {
      if (this.state.chatState === 'OPEN') {
        this.setState({ showIdleWarning: false });
        this.setChatState('LOCKED');
      }
    }, totalMs);
  }

  clearAutoLockTimer() {
    if (this.lockTimer) clearTimeout(this.lockTimer);
    if (this.warningTimer) clearTimeout(this.warningTimer);
    this.lockTimer = null;
    this.warningTimer = null;
    if (this.state.showIdleWarning) {
      this.setState({ showIdleWarning: false });
    }
  }

  private notify() {
    this.listeners.forEach((fn) => fn(this.state));
  }
}

export const uiStore = new UIStore();
