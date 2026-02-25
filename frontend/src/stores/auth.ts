import { defineStore } from 'pinia';

import { apiGet, apiPost } from '@/services/api';

type User = {
  id: number;
  email: string;
  role: 'user' | 'admin' | 'owner';
  is_active: boolean;
  is_seed: boolean;
  disabled_at?: string | null;
  created_at: string;
  updated_at?: string | null;
};

type AuthResponse = {
  user: User;
};

export const useAuthStore = defineStore('auth', {
  state: () => ({
    user: null as User | null,
    hydrating: false,
    hydrated: false
  }),
  getters: {
    isAuthenticated: (state) => state.user !== null,
    isAdmin: (state) => state.user?.role === 'admin' || state.user?.role === 'owner',
    isOwner: (state) => state.user?.role === 'owner'
  },
  actions: {
    async hydrate(): Promise<void> {
      if (this.hydrated || this.hydrating) {
        return;
      }

      this.hydrating = true;
      try {
        const response = await apiGet<AuthResponse>('/auth/me');
        this.user = response.user;
      } catch {
        this.user = null;
      } finally {
        this.hydrated = true;
        this.hydrating = false;
      }
    },
    async login(email: string, password: string): Promise<void> {
      const response = await apiPost<AuthResponse>('/auth/login', { email, password });
      this.user = response.user;
      this.hydrated = true;
    },
    async register(email: string, password: string): Promise<void> {
      const response = await apiPost<AuthResponse>('/auth/register', { email, password });
      this.user = response.user;
      this.hydrated = true;
    },
    async forgotPassword(email: string): Promise<void> {
      await apiPost('/auth/forgot-password', { email });
    },
    async logout(): Promise<void> {
      await apiPost('/auth/logout');
      this.user = null;
    }
  }
});
