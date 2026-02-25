import { defineStore } from 'pinia';

import { apiDelete, apiGet, apiPost, apiPut } from '@/services/api';
import { clearLocalReceipts, enqueue, getLocalReceipts, listQueue, putLocalReceipt, removeQueueItem, type LocalReceipt } from '@/services/idb';

export type Receipt = {
  id: string;
  merchant?: string;
  total?: number;
  currency?: string;
  purchased_at?: string;
  notes?: string;
  raw_text?: string;
  category?: string;
  tags?: string[];
  file_path?: string;
  processing_explanation?: unknown[];
  created_at?: string;
  updated_at?: string;
  offline?: boolean;
};

type ReceiptListResponse = {
  items: Receipt[];
};

type ReceiptSingleResponse = {
  item: Receipt;
};

export const useReceiptsStore = defineStore('receipts', {
  state: () => ({
    items: [] as Receipt[],
    syncing: false,
    loading: false,
    error: ''
  }),
  getters: {
    totalCount: (state) => state.items.length,
    totalAmount: (state) => state.items.reduce((sum, item) => sum + Number(item.total ?? 0), 0)
  },
  actions: {
    async fetchReceipts(): Promise<void> {
      this.loading = true;
      this.error = '';

      try {
        if (navigator.onLine) {
          const response = await apiGet<ReceiptListResponse>('/receipts');
          this.items = response.items;

          await clearLocalReceipts();
          await Promise.all(response.items.map((item) => putLocalReceipt({ ...item, offline: false } as LocalReceipt)));
        } else {
          const local = await getLocalReceipts();
          this.items = local as Receipt[];
        }
      } catch (error) {
        this.error = error instanceof Error ? error.message : 'Failed to load receipts';
      } finally {
        this.loading = false;
      }
    },

    async createReceipt(input: Record<string, unknown>, file?: File | null): Promise<Receipt> {
      this.error = '';

      try {
        if (!navigator.onLine && file) {
          throw new Error('Offline file uploads are not supported yet. Save without a file or reconnect.');
        }

        if (navigator.onLine && file) {
          const form = new FormData();
          Object.entries(input).forEach(([key, value]) => {
            if (value === undefined || value === null) {
              return;
            }
            if (Array.isArray(value)) {
              form.append(key, value.join(','));
              return;
            }
            form.append(key, String(value));
          });
          form.append('receipt', file);

          const response = await apiPost<ReceiptSingleResponse>('/receipts', form);
          this.items.unshift(response.item);
          return response.item;
        }

        if (navigator.onLine) {
          const response = await apiPost<ReceiptSingleResponse>('/receipts', input);
          this.items.unshift(response.item);
          await putLocalReceipt({ ...response.item, offline: false } as LocalReceipt);
          return response.item;
        }

        const offlineId = `offline-${crypto.randomUUID()}`;
        const draft: Receipt = {
          id: offlineId,
          merchant: String(input.merchant ?? ''),
          total: Number(input.total ?? 0),
          currency: String(input.currency ?? 'USD'),
          purchased_at: String(input.purchased_at ?? ''),
          notes: String(input.notes ?? ''),
          raw_text: String(input.raw_text ?? ''),
          category: String(input.category ?? ''),
          tags: Array.isArray(input.tags) ? (input.tags as string[]) : [],
          created_at: new Date().toISOString(),
          updated_at: new Date().toISOString(),
          offline: true
        };

        this.items.unshift(draft);
        await putLocalReceipt(draft as LocalReceipt);
        await enqueue({
          type: 'create_receipt',
          payload: input,
          createdAt: new Date().toISOString()
        });
        return draft;
      } catch (error) {
        this.error = error instanceof Error ? error.message : 'Failed to create receipt';
        throw error;
      }
    },

    async getById(id: string): Promise<Receipt | null> {
      const existing = this.items.find((item) => item.id === id);
      if (existing) {
        return existing;
      }

      if (!navigator.onLine) {
        const local = await getLocalReceipts();
        return (local.find((item) => item.id === id) as Receipt | undefined) ?? null;
      }

      try {
        const response = await apiGet<ReceiptSingleResponse>(`/receipts/${id}`);
        return response.item;
      } catch {
        return null;
      }
    },

    async updateReceipt(id: string, payload: Record<string, unknown>): Promise<void> {
      const response = await apiPut<ReceiptSingleResponse>(`/receipts/${id}`, payload);
      this.items = this.items.map((item) => (item.id === id ? response.item : item));
      await putLocalReceipt({ ...response.item, offline: false } as LocalReceipt);
    },

    async deleteReceipt(id: string): Promise<void> {
      await apiDelete<{ ok: boolean }>(`/receipts/${id}`);
      this.items = this.items.filter((item) => item.id !== id);
    },

    async processReceipt(id: string): Promise<void> {
      const response = await apiPost<ReceiptSingleResponse>(`/receipts/${id}/process`, {});
      this.items = this.items.map((item) => (item.id === id ? response.item : item));
      await putLocalReceipt({ ...response.item, offline: false } as LocalReceipt);
    },

    async search(query: string): Promise<Receipt[]> {
      if (!query.trim()) {
        return this.items;
      }

      if (!navigator.onLine) {
        const q = query.toLowerCase();
        return this.items.filter((item) =>
          [item.merchant, item.notes, item.raw_text, item.category].some((field) => String(field ?? '').toLowerCase().includes(q))
        );
      }

      const response = await apiGet<ReceiptListResponse>(`/search?q=${encodeURIComponent(query)}`);
      return response.items;
    },

    async syncQueue(): Promise<void> {
      if (!navigator.onLine || this.syncing) {
        return;
      }

      this.syncing = true;
      try {
        const queue = await listQueue();
        for (const item of queue) {
          if (item.type !== 'create_receipt' || item.id === undefined) {
            continue;
          }

          const response = await apiPost<ReceiptSingleResponse>('/receipts', item.payload);
          await removeQueueItem(item.id);
          await putLocalReceipt({ ...response.item, offline: false } as LocalReceipt);
        }

        await this.fetchReceipts();
      } finally {
        this.syncing = false;
      }
    }
  }
});
