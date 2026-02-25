import { defineStore } from 'pinia';

import { apiGet, apiPost } from '@/services/api';

type User = {
  id: number;
  email: string;
  created_at: string;
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
    isAuthenticated: (state) => state.user !== null
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
