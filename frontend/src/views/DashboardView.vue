<template>
  <section class="page dashboard-command-page">
    <article class="card dashboard-hero">
      <div class="dashboard-hero-layout">
        <div class="dashboard-hero-copy">
          <p class="kicker">Operations Hub</p>
          <h1>Welcome back{{ firstName ? `, ${firstName}` : '' }}</h1>
          <p class="muted">
            Monitor capture throughput, keep AI extraction current, and jump into your highest-impact actions.
          </p>

          <div class="dashboard-hero-actions">
            <router-link to="/app/capture"><button class="secondary">Capture Receipt</button></router-link>
            <router-link to="/app/receipts"><button class="ghost">Open Command Center</button></router-link>
            <button class="ghost" type="button" @click="syncNow" :disabled="receipts.syncing || !isOnline">
              {{ receipts.syncing ? 'Syncing...' : 'Sync Queue Now' }}
            </button>
          </div>
        </div>

        <div class="dashboard-hero-art" aria-hidden="true">
          <div class="dashboard-orb one"></div>
          <div class="dashboard-orb two"></div>
          <div class="dashboard-orb three"></div>
          <div class="dashboard-art-card">
            <span>Live Pipeline</span>
            <strong>{{ livePipelineLabel }}</strong>
            <p>{{ unprocessedCount }} pending AI · {{ offlineCount }} offline</p>
          </div>
        </div>
      </div>
    </article>

    <p v-if="note" class="success" style="margin-top: 0.85rem">{{ note }}</p>
    <p v-if="syncError" class="error" style="margin-top: 0.85rem">{{ syncError }}</p>

    <section class="dashboard-kpi-grid" style="margin-top: 1rem">
      <article class="card dashboard-kpi-card">
        <span>Total Receipts</span>
        <strong>{{ receipts.totalCount }}</strong>
      </article>
      <article class="card dashboard-kpi-card">
        <span>Total Spend</span>
        <strong>{{ formatCurrency(receipts.totalAmount, displayCurrency) }}</strong>
      </article>
      <article class="card dashboard-kpi-card">
        <span>Unprocessed</span>
        <strong>{{ unprocessedCount }}</strong>
      </article>
      <article class="card dashboard-kpi-card">
        <span>Offline Drafts</span>
        <strong>{{ offlineCount }}</strong>
      </article>
      <article class="card dashboard-kpi-card">
        <span>Avg AI Confidence</span>
        <strong>{{ avgAiConfidence !== null ? avgAiConfidence.toFixed(2) : '-' }}</strong>
      </article>
    </section>

    <section class="dashboard-quick-grid" style="margin-top: 1rem">
      <router-link class="dashboard-quick-card" to="/app/receipts">
        <strong>Receipts Command Center</strong>
        <p class="muted">Search, filter, inspect, and bulk-manage receipts at speed.</p>
      </router-link>
      <router-link class="dashboard-quick-card" to="/app/reports">
        <strong>Reports + AI Review</strong>
        <p class="muted">Generate trend dashboards, export data, and build executive summaries.</p>
      </router-link>
      <router-link class="dashboard-quick-card" to="/app/rules">
        <strong>Rule Automation Studio</strong>
        <p class="muted">Automate categorization, tags, and note normalization with no-code rules.</p>
      </router-link>
    </section>

    <div class="dashboard-main-grid" style="margin-top: 1rem">
      <article class="card dashboard-trend-card">
        <div class="inline" style="justify-content: space-between">
          <h3>Monthly Spend Trend</h3>
          <span class="muted">Last {{ monthlySeries.length }} months</span>
        </div>

        <div v-if="monthlySeries.length === 0" class="dashboard-empty muted">Not enough data to render trend.</div>
        <div v-else class="dashboard-trend-bars">
          <div v-for="entry in monthlySeries" :key="entry.period" class="dashboard-trend-col">
            <div class="dashboard-trend-track">
              <span class="dashboard-trend-fill" :style="{ height: `${trendHeight(entry.total)}%` }"></span>
            </div>
            <strong>{{ shortMonth(entry.period) }}</strong>
            <span>{{ formatCurrency(entry.total, displayCurrency) }}</span>
          </div>
        </div>
      </article>

      <article class="card dashboard-category-card">
        <h3>Category Spotlight</h3>

        <div v-if="topCategories.length === 0" class="dashboard-empty muted">No category data yet.</div>
        <div v-else class="dashboard-category-list">
          <div v-for="entry in topCategories" :key="entry.category" class="dashboard-category-row">
            <div class="inline" style="justify-content: space-between">
              <strong>{{ entry.category }}</strong>
              <span class="muted">{{ formatCurrency(entry.total, displayCurrency) }}</span>
            </div>
            <div class="dashboard-meter"><span :style="{ width: `${Math.max(4, entry.share)}%` }"></span></div>
            <p class="muted">{{ entry.count }} receipts · {{ entry.share.toFixed(1) }}%</p>
          </div>
        </div>
      </article>

      <article class="card dashboard-activity-card">
        <div class="inline" style="justify-content: space-between">
          <h3>Recent Activity</h3>
          <router-link to="/app/receipts" class="ghost dashboard-inline-link">View all</router-link>
        </div>

        <div v-if="recentReceipts.length === 0" class="dashboard-empty muted">Capture your first receipt to populate activity.</div>
        <div v-else class="dashboard-activity-list">
          <div v-for="item in recentReceipts" :key="item.id" class="dashboard-activity-row">
            <div>
              <strong>{{ item.merchant || 'Unknown merchant' }}</strong>
              <p class="muted">{{ effectiveDate(item) || '-' }} · {{ item.category || 'Uncategorized' }}</p>
            </div>
            <div class="dashboard-activity-meta">
              <strong>{{ formatCurrency(item.total, item.currency || displayCurrency) }}</strong>
              <router-link :to="`/app/receipts/${item.id}`">Open</router-link>
            </div>
          </div>
        </div>
      </article>

      <article class="card dashboard-insight-card">
        <h3>Suggested Next Actions</h3>
        <ul class="dashboard-insight-list">
          <li v-for="(insight, index) in suggestedActions" :key="`insight-${index}`">{{ insight }}</li>
        </ul>
      </article>
    </div>
  </section>
</template>

<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref } from 'vue';

import { useAuthStore } from '@/stores/auth';
import { useReceiptsStore, type Receipt } from '@/stores/receipts';

const receipts = useReceiptsStore();
const auth = useAuthStore();

const note = ref('');
const syncError = ref('');
const isOnline = ref(typeof navigator !== 'undefined' ? navigator.onLine : true);

const firstName = computed(() => {
  const email = auth.user?.email || '';
  const beforeAt = email.split('@')[0] || '';
  const clean = beforeAt.replace(/[^a-zA-Z0-9]+/g, ' ').trim();
  if (clean === '') {
    return '';
  }

  const firstToken = clean.split(' ')[0] || '';
  return firstToken.charAt(0).toUpperCase() + firstToken.slice(1);
});

const unprocessedCount = computed(() => receipts.items.filter((item) => !isProcessed(item) && !item.offline).length);
const offlineCount = computed(() => receipts.items.filter((item) => Boolean(item.offline)).length);

const displayCurrency = computed(() => {
  const firstCurrency = receipts.items.find((item) => (item.currency || '').trim() !== '')?.currency;
  return (firstCurrency || 'USD').toUpperCase();
});

const avgAiConfidence = computed(() => {
  const values = receipts.items
    .map((item) => item.ai_confidence)
    .filter((value): value is number => value !== null && value !== undefined && Number.isFinite(value));

  if (values.length === 0) {
    return null;
  }

  const sum = values.reduce((acc, value) => acc + value, 0);
  return sum / values.length;
});

const livePipelineLabel = computed(() => {
  if (!isOnline.value) {
    return 'Offline Mode';
  }

  if (receipts.syncing) {
    return 'Sync In Progress';
  }

  if (unprocessedCount.value > 0) {
    return 'AI Queue Ready';
  }

  return 'All Clear';
});

const monthlySeries = computed(() => {
  const monthlyMap = new Map<string, { period: string; total: number }>();

  for (const item of receipts.items) {
    const date = effectiveDate(item);
    if (!date || !/^\d{4}-\d{2}-\d{2}$/.test(date)) {
      continue;
    }

    const period = date.slice(0, 7);
    const existing = monthlyMap.get(period) ?? { period, total: 0 };
    existing.total += Number(item.total ?? 0);
    monthlyMap.set(period, existing);
  }

  const sorted = Array.from(monthlyMap.values()).sort((a, b) => a.period.localeCompare(b.period));
  return sorted.slice(-8);
});

const trendMax = computed(() => {
  const max = monthlySeries.value.reduce((highest, entry) => Math.max(highest, entry.total), 0);
  return max <= 0 ? 1 : max;
});

const topCategories = computed(() => {
  const map = new Map<string, { category: string; total: number; count: number }>();

  for (const item of receipts.items) {
    const category = (item.category || 'Uncategorized').trim() || 'Uncategorized';
    const existing = map.get(category) ?? { category, total: 0, count: 0 };
    existing.total += Number(item.total ?? 0);
    existing.count += 1;
    map.set(category, existing);
  }

  const rows = Array.from(map.values()).sort((a, b) => b.total - a.total).slice(0, 5);
  const sum = rows.reduce((acc, row) => acc + row.total, 0);

  return rows.map((row) => ({
    ...row,
    share: sum > 0 ? (row.total / sum) * 100 : 0
  }));
});

const recentReceipts = computed(() => {
  const sorted = [...receipts.items].sort((a, b) => effectiveDateKey(b).localeCompare(effectiveDateKey(a)));
  return sorted.slice(0, 7);
});

const suggestedActions = computed(() => {
  const actions: string[] = [];

  if (unprocessedCount.value > 0) {
    actions.push(`Process ${unprocessedCount.value} pending receipt${unprocessedCount.value === 1 ? '' : 's'} to improve search and reporting accuracy.`);
  }

  if (offlineCount.value > 0) {
    actions.push(`Sync ${offlineCount.value} offline draft${offlineCount.value === 1 ? '' : 's'} to avoid missing data in reports.`);
  }

  const topCategory = topCategories.value[0];
  if (topCategory && topCategory.share >= 45) {
    actions.push(`Create a budget or rule for ${topCategory.category}; it currently represents ${topCategory.share.toFixed(1)}% of top-category spend.`);
  }

  if (receipts.items.length >= 12 && avgAiConfidence.value !== null && avgAiConfidence.value < 0.7) {
    actions.push('AI confidence is trending low. Tighten capture framing and review edge adjustments before upload.');
  }

  if (actions.length === 0) {
    actions.push('Everything looks healthy. Keep captures consistent and review reports weekly for trends.');
    actions.push('Consider creating another rule template to automate recurring merchant categorization.');
  }

  return actions.slice(0, 5);
});

onMounted(async () => {
  await receipts.fetchReceipts();

  if (typeof window !== 'undefined') {
    window.addEventListener('online', onNetworkChange);
    window.addEventListener('offline', onNetworkChange);
  }
});

onUnmounted(() => {
  if (typeof window !== 'undefined') {
    window.removeEventListener('online', onNetworkChange);
    window.removeEventListener('offline', onNetworkChange);
  }
});

function onNetworkChange(): void {
  isOnline.value = navigator.onLine;
}

function effectiveDate(item: Receipt): string {
  if (item.purchased_at && /^\d{4}-\d{2}-\d{2}$/.test(item.purchased_at)) {
    return item.purchased_at;
  }

  return item.created_at ? item.created_at.slice(0, 10) : '';
}

function effectiveDateKey(item: Receipt): string {
  const date = effectiveDate(item);
  if (date !== '') {
    return date;
  }

  return item.created_at || '';
}

function shortMonth(period: string): string {
  const parsed = new Date(`${period}-01T00:00:00Z`);
  if (Number.isNaN(parsed.getTime())) {
    return period;
  }

  return parsed.toLocaleDateString(undefined, { month: 'short', year: '2-digit' });
}

function trendHeight(total: number): number {
  if (total <= 0) {
    return 4;
  }

  return Math.max(8, (total / trendMax.value) * 100);
}

function isProcessed(item: Receipt): boolean {
  return Boolean((item.processed_at || '').trim());
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

async function syncNow(): Promise<void> {
  note.value = '';
  syncError.value = '';

  try {
    await receipts.syncQueue();
    await receipts.fetchReceipts();
    note.value = 'Sync complete.';
  } catch (err) {
    syncError.value = err instanceof Error ? err.message : 'Sync failed.';
  }
}
</script>
