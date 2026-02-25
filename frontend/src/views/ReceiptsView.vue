<template>
  <section class="page receipts-command-page">
    <header class="receipts-command-head">
      <div>
        <p class="kicker">Receipt Command Center</p>
        <h1>Receipts</h1>
        <p class="muted">Search faster, inspect instantly, and manage receipts in bulk.</p>
      </div>
      <div class="receipts-command-actions">
        <button class="ghost" type="button" @click="refreshReceipts" :disabled="receipts.loading">Refresh</button>
        <router-link to="/app/capture"><button class="secondary">Capture New</button></router-link>
      </div>
    </header>

    <p v-if="error" class="error" style="margin-top: 0.85rem">{{ error }}</p>
    <p v-if="note" class="success" style="margin-top: 0.85rem">{{ note }}</p>
    <p v-if="receipts.error" class="error" style="margin-top: 0.85rem">{{ receipts.error }}</p>

    <article class="card receipts-toolbar" style="margin-top: 1rem">
      <div>
        <label for="smart-search">Smart Search</label>
        <input
          id="smart-search"
          v-model="query"
          placeholder="Try: merchant:uber min:20 from:2026-01-01 tag:business has:notes"
          autocomplete="off"
        />
      </div>

      <div class="receipts-smart-hints">
        <button
          v-for="hint in queryHints"
          :key="hint"
          type="button"
          class="receipts-hint-chip"
          @click="appendToken(hint)"
        >
          {{ hint }}
        </button>
      </div>

      <div class="receipts-toolbar-grid">
        <label>
          Sort
          <select v-model="sortBy">
            <option value="date_desc">Newest first</option>
            <option value="date_asc">Oldest first</option>
            <option value="amount_desc">Highest amount</option>
            <option value="amount_asc">Lowest amount</option>
            <option value="merchant_asc">Merchant A-Z</option>
            <option value="merchant_desc">Merchant Z-A</option>
          </select>
        </label>

        <label>
          Time Window
          <select v-model="timeWindow">
            <option value="all">All time</option>
            <option value="30">Last 30 days</option>
            <option value="90">Last 90 days</option>
            <option value="365">Last 12 months</option>
          </select>
        </label>

        <label>
          Quick Category
          <select v-model="quickCategory">
            <option value="">All categories</option>
            <option v-for="category in categoryOptions" :key="category" :value="category">{{ category }}</option>
          </select>
        </label>
      </div>

      <div class="receipts-toggle-row">
        <label class="receipts-check">
          <input v-model="showOnlyUnprocessed" type="checkbox" />
          <span>Unprocessed only</span>
        </label>
        <label class="receipts-check">
          <input v-model="showOnlyWithNotes" type="checkbox" />
          <span>With notes only</span>
        </label>
        <label class="receipts-check">
          <input v-model="showOnlyOffline" type="checkbox" />
          <span>Offline drafts only</span>
        </label>

        <button class="ghost" type="button" @click="clearFilters">Clear Filters</button>
      </div>
    </article>

    <section class="receipts-kpi-grid" style="margin-top: 1rem">
      <article class="card receipts-kpi-card">
        <span>Visible Receipts</span>
        <strong>{{ visibleReceipts.length }}</strong>
      </article>
      <article class="card receipts-kpi-card">
        <span>Visible Spend</span>
        <strong>{{ formatCurrency(visibleSpendTotal, summaryCurrency) }}</strong>
      </article>
      <article class="card receipts-kpi-card">
        <span>Average Receipt</span>
        <strong>{{ formatCurrency(visibleAverage, summaryCurrency) }}</strong>
      </article>
      <article class="card receipts-kpi-card">
        <span>Needs Processing</span>
        <strong>{{ unprocessedVisibleCount }}</strong>
      </article>
    </section>

    <article v-if="selectedCount > 0" class="card receipts-bulk-bar" style="margin-top: 1rem">
      <div>
        <strong>{{ selectedCount }}</strong> selected
      </div>
      <div class="receipts-bulk-actions">
        <button class="secondary" type="button" @click="processSelected" :disabled="processingBulk || selectedProcessableCount === 0">
          {{ processingBulk ? 'Processing...' : `Process Selected (${selectedProcessableCount})` }}
        </button>
        <button class="ghost" type="button" @click="clearSelection">Clear</button>
        <button class="ghost" type="button" @click="deleteSelected" :disabled="deletingBulk">Delete Selected</button>
      </div>
    </article>

    <div class="receipts-command-grid" style="margin-top: 1rem">
      <article class="card receipts-list-panel">
        <div class="inline" style="justify-content: space-between">
          <div>
            <h3>Receipt Queue</h3>
            <p class="muted">{{ visibleReceipts.length }} results from {{ receipts.items.length }} total receipts.</p>
          </div>
          <button class="ghost" type="button" @click="toggleSelectVisible" :disabled="visibleReceipts.length === 0">
            {{ allVisibleSelected ? 'Unselect Visible' : 'Select Visible' }}
          </button>
        </div>

        <div v-if="visibleReceipts.length === 0" class="receipts-empty-state">
          <h4>No receipts match these filters.</h4>
          <p class="muted">Adjust your smart query or clear filters to broaden results.</p>
        </div>

        <div v-else class="receipts-table-wrap" style="margin-top: 0.75rem; overflow-x: auto">
          <table class="table receipts-command-table">
            <thead>
              <tr>
                <th style="width: 46px"></th>
                <th>Merchant & Date</th>
                <th>Category & Tags</th>
                <th>Total</th>
                <th>Status</th>
                <th style="width: 215px"></th>
              </tr>
            </thead>
            <tbody>
              <tr
                v-for="item in visibleReceipts"
                :key="item.id"
                :class="{ 'is-active': activeReceiptId === item.id }"
                @click="setActive(item.id)"
              >
                <td>
                  <input
                    type="checkbox"
                    :checked="isSelected(item.id)"
                    @click.stop
                    @change="toggleSelect(item.id)"
                    :aria-label="`Select ${item.merchant || 'receipt'}`"
                  />
                </td>
                <td>
                  <strong>{{ item.merchant || 'Unknown merchant' }}</strong>
                  <div class="muted">{{ effectiveDate(item) || '-' }}</div>
                  <div v-if="item.receipt_number" class="muted">#{{ item.receipt_number }}</div>
                </td>
                <td>
                  <div>{{ item.category || '-' }}</div>
                  <div class="receipts-inline-tags">
                    <span v-for="tag in (item.tags || []).slice(0, 3)" :key="`${item.id}-tag-${tag}`">#{{ tag }}</span>
                  </div>
                </td>
                <td>{{ formatCurrency(item.total, item.currency || summaryCurrency) }}</td>
                <td>
                  <span class="pill" :class="statusClass(item)">{{ statusLabel(item) }}</span>
                </td>
                <td>
                  <div class="receipts-row-actions" @click.stop>
                    <button class="ghost" type="button" @click="setActive(item.id)">Inspect</button>
                    <button class="ghost" type="button" @click="processOne(item.id)" :disabled="item.offline || isProcessed(item)">
                      Process
                    </button>
                    <router-link class="ghost receipts-action-link" :to="`/app/receipts/${item.id}`">Open</router-link>
                    <button class="ghost" type="button" @click="deleteOne(item.id)">Delete</button>
                  </div>
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <div class="receipts-mobile-list" style="margin-top: 0.75rem">
          <article
            v-for="item in visibleReceipts"
            :key="`mobile-${item.id}`"
            class="receipt-mobile-card receipts-mobile-command"
            :class="{ 'is-active': activeReceiptId === item.id }"
            @click="setActive(item.id)"
          >
            <div class="receipt-mobile-top">
              <strong>{{ item.merchant || 'Unknown Merchant' }}</strong>
              <strong>{{ formatCurrency(item.total, item.currency || summaryCurrency) }}</strong>
            </div>
            <div class="receipt-mobile-meta">
              <span>Date: {{ effectiveDate(item) || '-' }}</span>
              <span>Category: {{ item.category || '-' }}</span>
            </div>
            <div class="receipts-inline-tags">
              <span v-for="tag in (item.tags || []).slice(0, 3)" :key="`mobile-${item.id}-tag-${tag}`">#{{ tag }}</span>
            </div>
            <div class="receipts-row-actions" @click.stop>
              <button class="ghost" type="button" @click="toggleSelect(item.id)">{{ isSelected(item.id) ? 'Unselect' : 'Select' }}</button>
              <router-link class="ghost receipts-action-link" :to="`/app/receipts/${item.id}`">Open</router-link>
              <button class="ghost" type="button" @click="processOne(item.id)" :disabled="item.offline || isProcessed(item)">Process</button>
            </div>
          </article>
        </div>
      </article>

      <aside class="card receipts-inspector-panel">
        <h3>Inspector</h3>

        <div v-if="activeReceipt" class="receipts-inspector-content">
          <div class="inline" style="justify-content: space-between">
            <strong>{{ activeReceipt.merchant || 'Unknown merchant' }}</strong>
            <span class="pill" :class="statusClass(activeReceipt)">{{ statusLabel(activeReceipt) }}</span>
          </div>

          <div class="receipts-inspector-grid">
            <div><span>Date</span><strong>{{ effectiveDate(activeReceipt) || '-' }}</strong></div>
            <div><span>Total</span><strong>{{ formatCurrency(activeReceipt.total, activeReceipt.currency || summaryCurrency) }}</strong></div>
            <div><span>Category</span><strong>{{ activeReceipt.category || '-' }}</strong></div>
            <div><span>Currency</span><strong>{{ activeReceipt.currency || summaryCurrency }}</strong></div>
          </div>

          <div v-if="(activeReceipt.tags || []).length > 0" class="receipts-inline-tags">
            <span v-for="tag in activeReceipt.tags || []" :key="`inspector-${activeReceipt.id}-tag-${tag}`">#{{ tag }}</span>
          </div>

          <p class="muted" v-if="activeReceipt.notes">{{ activeReceipt.notes }}</p>
          <p class="muted" v-else>No notes available.</p>

          <div class="receipts-inspector-stats">
            <div>
              <span>Line Items</span>
              <strong>{{ (activeReceipt.line_items || []).length }}</strong>
            </div>
            <div>
              <span>AI Confidence</span>
              <strong>{{ activeReceipt.ai_confidence !== undefined && activeReceipt.ai_confidence !== null ? activeReceipt.ai_confidence.toFixed(2) : '-' }}</strong>
            </div>
            <div>
              <span>Processed At</span>
              <strong>{{ activeReceipt.processed_at || '-' }}</strong>
            </div>
          </div>

          <div class="receipts-inspector-actions">
            <button class="secondary" type="button" @click="processOne(activeReceipt.id)" :disabled="activeReceipt.offline || isProcessed(activeReceipt)">Run AI Processing</button>
            <router-link class="ghost receipts-action-link" :to="`/app/receipts/${activeReceipt.id}`">Open Full Receipt</router-link>
          </div>
        </div>

        <p v-else class="muted">Select a receipt row to inspect details and run quick actions.</p>
      </aside>
    </div>
  </section>
</template>

<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';

import type { Receipt } from '@/stores/receipts';
import { useReceiptsStore } from '@/stores/receipts';

type SmartFilters = {
  textTerms: string[];
  merchantTerms: string[];
  categoryTerms: string[];
  tagTerms: string[];
  minAmount: number | null;
  maxAmount: number | null;
  fromDate: string | null;
  toDate: string | null;
  hasFlags: Set<string>;
  statusFlags: Set<string>;
};

const receipts = useReceiptsStore();

const query = ref('');
const sortBy = ref<'date_desc' | 'date_asc' | 'amount_desc' | 'amount_asc' | 'merchant_asc' | 'merchant_desc'>('date_desc');
const timeWindow = ref<'all' | '30' | '90' | '365'>('all');
const quickCategory = ref('');
const showOnlyUnprocessed = ref(false);
const showOnlyWithNotes = ref(false);
const showOnlyOffline = ref(false);

const selectedIds = ref<string[]>([]);
const activeReceiptId = ref('');

const processingBulk = ref(false);
const deletingBulk = ref(false);
const error = ref('');
const note = ref('');

const queryHints = ['merchant:', 'category:', 'tag:', 'min:', 'max:', 'from:', 'to:', 'has:notes', 'status:unprocessed'];

const categoryOptions = computed(() => {
  const set = new Set<string>();
  for (const item of receipts.items) {
    const category = (item.category || '').trim();
    if (category !== '') {
      set.add(category);
    }
  }

  return Array.from(set).sort((a, b) => a.localeCompare(b));
});

const parsedFilters = computed(() => parseSmartQuery(query.value));

const filteredReceipts = computed(() => receipts.items.filter((item) => matchesFilters(item, parsedFilters.value)));

const visibleReceipts = computed(() => {
  const items = [...filteredReceipts.value];

  if (quickCategory.value.trim() !== '') {
    const targetCategory = quickCategory.value.trim().toLowerCase();
    items.splice(0, items.length, ...items.filter((item) => (item.category || '').toLowerCase() === targetCategory));
  }

  if (showOnlyUnprocessed.value) {
    items.splice(0, items.length, ...items.filter((item) => !isProcessed(item)));
  }

  if (showOnlyWithNotes.value) {
    items.splice(0, items.length, ...items.filter((item) => (item.notes || '').trim() !== ''));
  }

  if (showOnlyOffline.value) {
    items.splice(0, items.length, ...items.filter((item) => Boolean(item.offline)));
  }

  sortItems(items, sortBy.value);
  return items;
});

const selectedCount = computed(() => selectedIds.value.length);
const selectedProcessableCount = computed(() => selectedReceipts.value.filter((item) => !item.offline).length);
const selectedReceipts = computed(() => {
  const set = new Set(selectedIds.value);
  return receipts.items.filter((item) => set.has(item.id));
});

const allVisibleSelected = computed(() => {
  if (visibleReceipts.value.length === 0) {
    return false;
  }

  const selectedSet = new Set(selectedIds.value);
  return visibleReceipts.value.every((item) => selectedSet.has(item.id));
});

const activeReceipt = computed<Receipt | null>(() => {
  const preferred = receipts.items.find((item) => item.id === activeReceiptId.value);
  if (preferred) {
    return preferred;
  }

  if (selectedReceipts.value.length > 0) {
    return selectedReceipts.value[0];
  }

  return visibleReceipts.value[0] ?? null;
});

const summaryCurrency = computed(() => {
  const first = visibleReceipts.value.find((item) => (item.currency || '').trim() !== '');
  return (first?.currency || 'USD').toUpperCase();
});

const visibleSpendTotal = computed(() => visibleReceipts.value.reduce((sum, item) => sum + Number(item.total ?? 0), 0));
const visibleAverage = computed(() => {
  if (visibleReceipts.value.length === 0) {
    return 0;
  }
  return visibleSpendTotal.value / visibleReceipts.value.length;
});
const unprocessedVisibleCount = computed(() => visibleReceipts.value.filter((item) => !isProcessed(item)).length);

watch(
  () => receipts.items,
  (items) => {
    const validIds = new Set(items.map((item) => item.id));
    selectedIds.value = selectedIds.value.filter((id) => validIds.has(id));

    if (activeReceiptId.value !== '' && !validIds.has(activeReceiptId.value)) {
      activeReceiptId.value = '';
    }
  },
  { deep: true }
);

watch(
  () => visibleReceipts.value,
  (items) => {
    if (items.length === 0) {
      return;
    }

    if (activeReceiptId.value === '' || !items.some((item) => item.id === activeReceiptId.value)) {
      activeReceiptId.value = items[0].id;
    }
  },
  { immediate: true }
);

onMounted(async () => {
  await receipts.fetchReceipts();
});

function parseSmartQuery(rawQuery: string): SmartFilters {
  const filters: SmartFilters = {
    textTerms: [],
    merchantTerms: [],
    categoryTerms: [],
    tagTerms: [],
    minAmount: null,
    maxAmount: null,
    fromDate: null,
    toDate: null,
    hasFlags: new Set<string>(),
    statusFlags: new Set<string>()
  };

  const chunks = rawQuery
    .trim()
    .split(/\s+/)
    .map((chunk) => chunk.trim())
    .filter((chunk) => chunk !== '');

  for (const chunk of chunks) {
    const separatorIndex = chunk.indexOf(':');
    if (separatorIndex <= 0) {
      filters.textTerms.push(chunk.toLowerCase());
      continue;
    }

    const key = chunk.slice(0, separatorIndex).toLowerCase();
    const value = chunk.slice(separatorIndex + 1).trim();
    if (value === '') {
      continue;
    }

    switch (key) {
      case 'merchant':
      case 'm':
        filters.merchantTerms.push(value.toLowerCase());
        break;
      case 'category':
      case 'cat':
        filters.categoryTerms.push(value.toLowerCase());
        break;
      case 'tag':
      case 't':
        filters.tagTerms.push(value.toLowerCase());
        break;
      case 'min':
        if (Number.isFinite(Number(value))) {
          filters.minAmount = Number(value);
        }
        break;
      case 'max':
        if (Number.isFinite(Number(value))) {
          filters.maxAmount = Number(value);
        }
        break;
      case 'from':
        if (/^\d{4}-\d{2}-\d{2}$/.test(value)) {
          filters.fromDate = value;
        }
        break;
      case 'to':
        if (/^\d{4}-\d{2}-\d{2}$/.test(value)) {
          filters.toDate = value;
        }
        break;
      case 'has':
        filters.hasFlags.add(value.toLowerCase());
        break;
      case 'status':
      case 'is':
        filters.statusFlags.add(value.toLowerCase());
        break;
      default:
        filters.textTerms.push(chunk.toLowerCase());
    }
  }

  return filters;
}

function matchesFilters(item: Receipt, filters: SmartFilters): boolean {
  const merchant = (item.merchant || '').toLowerCase();
  const category = (item.category || '').toLowerCase();
  const notes = (item.notes || '').toLowerCase();
  const rawText = (item.raw_text || '').toLowerCase();
  const paymentMethod = (item.payment_method || '').toLowerCase();
  const receiptNumber = (item.receipt_number || '').toLowerCase();
  const tags = (item.tags || []).map((tag) => tag.toLowerCase());
  const total = Number(item.total ?? 0);
  const date = effectiveDate(item);

  if (timeWindow.value !== 'all') {
    const days = Number(timeWindow.value);
    const end = new Date();
    end.setHours(23, 59, 59, 999);
    const start = new Date(end);
    start.setDate(start.getDate() - (days - 1));

    if (!date || date < formatDate(start) || date > formatDate(end)) {
      return false;
    }
  }

  for (const term of filters.merchantTerms) {
    if (!merchant.includes(term)) {
      return false;
    }
  }

  for (const term of filters.categoryTerms) {
    if (!category.includes(term)) {
      return false;
    }
  }

  for (const term of filters.tagTerms) {
    if (!tags.some((tag) => tag.includes(term))) {
      return false;
    }
  }

  if (filters.minAmount !== null && total < filters.minAmount) {
    return false;
  }

  if (filters.maxAmount !== null && total > filters.maxAmount) {
    return false;
  }

  if (filters.fromDate !== null && (!date || date < filters.fromDate)) {
    return false;
  }

  if (filters.toDate !== null && (!date || date > filters.toDate)) {
    return false;
  }

  for (const flag of filters.hasFlags) {
    if (flag === 'notes' && (item.notes || '').trim() === '') {
      return false;
    }
    if (flag === 'items' && (item.line_items || []).length === 0) {
      return false;
    }
    if (flag === 'image' && !item.file_path) {
      return false;
    }
    if (flag === 'category' && (item.category || '').trim() === '') {
      return false;
    }
  }

  for (const flag of filters.statusFlags) {
    if (flag === 'offline' && !item.offline) {
      return false;
    }
    if (flag === 'online' && item.offline) {
      return false;
    }
    if (flag === 'processed' && !isProcessed(item)) {
      return false;
    }
    if (flag === 'unprocessed' && isProcessed(item)) {
      return false;
    }
  }

  if (filters.textTerms.length > 0) {
    const haystack = [merchant, category, notes, rawText, paymentMethod, receiptNumber, tags.join(' ')].join(' ');
    for (const term of filters.textTerms) {
      if (!haystack.includes(term)) {
        return false;
      }
    }
  }

  return true;
}

function sortItems(items: Receipt[], mode: string): void {
  const byDateDesc = (a: Receipt, b: Receipt) => compareDateKey(effectiveDateKey(b), effectiveDateKey(a));

  switch (mode) {
    case 'date_asc':
      items.sort((a, b) => compareDateKey(effectiveDateKey(a), effectiveDateKey(b)));
      break;
    case 'amount_desc':
      items.sort((a, b) => Number(b.total ?? 0) - Number(a.total ?? 0));
      break;
    case 'amount_asc':
      items.sort((a, b) => Number(a.total ?? 0) - Number(b.total ?? 0));
      break;
    case 'merchant_asc':
      items.sort((a, b) => (a.merchant || '').localeCompare(b.merchant || ''));
      break;
    case 'merchant_desc':
      items.sort((a, b) => (b.merchant || '').localeCompare(a.merchant || ''));
      break;
    case 'date_desc':
    default:
      items.sort(byDateDesc);
      break;
  }
}

function compareDateKey(a: string, b: string): number {
  if (a === b) {
    return 0;
  }
  return a > b ? 1 : -1;
}

function effectiveDate(item: Receipt): string {
  if (item.purchased_at && /^\d{4}-\d{2}-\d{2}$/.test(item.purchased_at)) {
    return item.purchased_at;
  }

  const created = item.created_at || '';
  return created.length >= 10 ? created.slice(0, 10) : '';
}

function effectiveDateKey(item: Receipt): string {
  const date = effectiveDate(item);
  if (date !== '') {
    return date;
  }

  return item.created_at || '';
}

function formatDate(date: Date): string {
  const year = date.getFullYear();
  const month = String(date.getMonth() + 1).padStart(2, '0');
  const day = String(date.getDate()).padStart(2, '0');
  return `${year}-${month}-${day}`;
}

function formatCurrency(value: number | null | undefined, currency: string): string {
  const amount = Number(value ?? 0);
  const safeCurrency = (currency || 'USD').toUpperCase();

  try {
    return new Intl.NumberFormat('en-US', {
      style: 'currency',
      currency: safeCurrency,
      minimumFractionDigits: 2,
      maximumFractionDigits: 2
    }).format(Number.isFinite(amount) ? amount : 0);
  } catch {
    return `$${(Number.isFinite(amount) ? amount : 0).toFixed(2)} ${safeCurrency}`;
  }
}

function statusLabel(item: Receipt): string {
  if (item.offline) {
    return 'Offline';
  }

  return isProcessed(item) ? 'Processed' : 'Pending AI';
}

function statusClass(item: Receipt): string {
  if (item.offline) {
    return 'trace-status-skipped';
  }

  return isProcessed(item) ? 'trace-status-success' : 'trace-status-default';
}

function isProcessed(item: Receipt): boolean {
  return Boolean((item.processed_at || '').trim());
}

function appendToken(token: string): void {
  if (query.value.trim() === '') {
    query.value = token;
    return;
  }

  if (query.value.endsWith(' ')) {
    query.value += token;
  } else {
    query.value += ` ${token}`;
  }
}

function clearFilters(): void {
  query.value = '';
  sortBy.value = 'date_desc';
  timeWindow.value = 'all';
  quickCategory.value = '';
  showOnlyUnprocessed.value = false;
  showOnlyWithNotes.value = false;
  showOnlyOffline.value = false;
}

function isSelected(id: string): boolean {
  return selectedIds.value.includes(id);
}

function toggleSelect(id: string): void {
  if (isSelected(id)) {
    selectedIds.value = selectedIds.value.filter((selectedId) => selectedId !== id);
  } else {
    selectedIds.value = [...selectedIds.value, id];
  }
}

function toggleSelectVisible(): void {
  const visibleIds = visibleReceipts.value.map((item) => item.id);
  if (visibleIds.length === 0) {
    return;
  }

  if (allVisibleSelected.value) {
    selectedIds.value = selectedIds.value.filter((id) => !visibleIds.includes(id));
    return;
  }

  const merged = new Set([...selectedIds.value, ...visibleIds]);
  selectedIds.value = Array.from(merged);
}

function clearSelection(): void {
  selectedIds.value = [];
}

function setActive(id: string): void {
  activeReceiptId.value = id;
}

async function processOne(id: string): Promise<void> {
  error.value = '';
  note.value = '';

  try {
    await receipts.processReceipt(id);
    note.value = 'Receipt processed.';
  } catch (err) {
    error.value = err instanceof Error ? err.message : 'Unable to process receipt';
  }
}

async function deleteOne(id: string): Promise<void> {
  if (!window.confirm('Delete this receipt? This action cannot be undone.')) {
    return;
  }

  error.value = '';
  note.value = '';

  try {
    await receipts.deleteReceipt(id);
    selectedIds.value = selectedIds.value.filter((itemId) => itemId !== id);
    note.value = 'Receipt deleted.';
  } catch (err) {
    error.value = err instanceof Error ? err.message : 'Unable to delete receipt';
  }
}

async function processSelected(): Promise<void> {
  const targets = selectedReceipts.value.filter((item) => !item.offline).map((item) => item.id);
  if (targets.length === 0) {
    return;
  }

  processingBulk.value = true;
  error.value = '';
  note.value = '';

  try {
    for (const id of targets) {
      await receipts.processReceipt(id);
    }
    note.value = `Processed ${targets.length} receipt${targets.length === 1 ? '' : 's'}.`;
  } catch (err) {
    error.value = err instanceof Error ? err.message : 'Unable to process selected receipts';
  } finally {
    processingBulk.value = false;
  }
}

async function deleteSelected(): Promise<void> {
  if (selectedIds.value.length === 0) {
    return;
  }

  if (!window.confirm(`Delete ${selectedIds.value.length} selected receipt(s)? This cannot be undone.`)) {
    return;
  }

  deletingBulk.value = true;
  error.value = '';
  note.value = '';

  try {
    const ids = [...selectedIds.value];
    for (const id of ids) {
      await receipts.deleteReceipt(id);
    }
    selectedIds.value = [];
    note.value = `Deleted ${ids.length} receipt${ids.length === 1 ? '' : 's'}.`;
  } catch (err) {
    error.value = err instanceof Error ? err.message : 'Unable to delete selected receipts';
  } finally {
    deletingBulk.value = false;
  }
}

async function refreshReceipts(): Promise<void> {
  error.value = '';
  note.value = '';

  try {
    await receipts.fetchReceipts();
    note.value = 'Receipts refreshed.';
  } catch (err) {
    error.value = err instanceof Error ? err.message : 'Unable to refresh receipts';
  }
}
</script>
