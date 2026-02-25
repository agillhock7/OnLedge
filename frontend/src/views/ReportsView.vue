<template>
  <section class="page reports-page">
    <header class="reports-head">
      <div>
        <p class="kicker">Reporting Workspace</p>
        <h1>Reports & Trends</h1>
        <p class="muted">Track spending patterns, surface anomalies, and generate an AI executive report.</p>
      </div>
      <div class="reports-head-actions">
        <button class="secondary" type="button" @click="downloadCsv">Download CSV</button>
      </div>
    </header>

    <article class="card reports-filter-card" style="margin-top: 1rem">
      <div class="reports-presets" role="group" aria-label="Time range presets">
        <button
          v-for="presetOption in presets"
          :key="presetOption.key"
          type="button"
          class="reports-preset"
          :class="{ active: selectedPreset === presetOption.key }"
          @click="setPreset(presetOption.key)"
        >
          {{ presetOption.label }}
        </button>
      </div>

      <div class="reports-filter-grid">
        <label>
          From
          <input type="date" v-model="from" />
        </label>
        <label>
          To
          <input type="date" v-model="to" />
        </label>
        <div class="reports-filter-actions">
          <button class="primary" type="button" @click="loadOverview" :disabled="loadingOverview">
            {{ loadingOverview ? 'Refreshing...' : 'Apply Range' }}
          </button>
          <button class="ghost" type="button" @click="generateAiReport" :disabled="loadingAi || loadingOverview">
            {{ loadingAi ? 'Generating AI Report...' : 'Generate AI Review' }}
          </button>
        </div>
      </div>
    </article>

    <p v-if="error" class="error" style="margin-top: 0.9rem">{{ error }}</p>
    <p v-if="note" class="success" style="margin-top: 0.9rem">{{ note }}</p>

    <section v-if="summary.receipt_count === 0 && !loadingOverview" class="card reports-empty" style="margin-top: 1rem">
      <h3>No receipts in this window yet</h3>
      <p class="muted">Try a wider date range or capture more receipts to unlock trend insights and AI reporting.</p>
    </section>

    <template v-else>
      <section class="reports-kpis" style="margin-top: 1rem">
        <article class="card report-kpi-card">
          <span>Receipts</span>
          <strong>{{ summary.receipt_count }}</strong>
        </article>
        <article class="card report-kpi-card">
          <span>Total Spend</span>
          <strong>{{ formatCurrency(summary.spend_total) }}</strong>
        </article>
        <article class="card report-kpi-card">
          <span>Average Receipt</span>
          <strong>{{ formatCurrency(summary.average_receipt) }}</strong>
        </article>
        <article class="card report-kpi-card">
          <span>Largest Purchase</span>
          <strong>{{ formatCurrency(summary.largest_purchase) }}</strong>
        </article>
        <article class="card report-kpi-card">
          <span>Active Days</span>
          <strong>{{ summary.active_days }}</strong>
        </article>
      </section>

      <section class="reports-grid" style="margin-top: 1rem">
        <article class="card reports-trend-card">
          <div class="inline" style="justify-content: space-between">
            <h3>Monthly Spend Trend</h3>
            <span class="muted">Last {{ monthlySeries.length }} months</span>
          </div>

          <div v-if="monthlySeries.length === 0" class="muted">Not enough dated receipts to build a trend.</div>
          <div v-else class="reports-trend-bars">
            <div v-for="entry in monthlySeries" :key="entry.period" class="reports-trend-col">
              <div class="reports-trend-track">
                <span class="reports-trend-fill" :style="{ height: `${trendHeight(entry.total)}%` }"></span>
              </div>
              <strong>{{ shortMonth(entry.period) }}</strong>
              <span>{{ formatCurrency(entry.total) }}</span>
            </div>
          </div>
        </article>

        <article class="card">
          <h3>Category Mix</h3>
          <div v-if="categorySeries.length === 0" class="muted">No category data available.</div>
          <div v-else class="reports-category-list">
            <div v-for="entry in categorySeries" :key="entry.category" class="reports-category-row">
              <div class="inline" style="justify-content: space-between">
                <strong>{{ entry.category }}</strong>
                <span class="muted">{{ formatCurrency(entry.total) }} · {{ entry.share.toFixed(1) }}%</span>
              </div>
              <div class="reports-meter" aria-hidden="true">
                <span :style="{ width: `${Math.max(4, entry.share)}%` }"></span>
              </div>
            </div>
          </div>
        </article>

        <article class="card">
          <h3>Top Merchants</h3>
          <div v-if="merchantSeries.length === 0" class="muted">No merchant data available.</div>
          <div v-else class="reports-merchant-list">
            <div v-for="entry in merchantSeries" :key="entry.merchant" class="reports-merchant-row">
              <div>
                <strong>{{ entry.merchant }}</strong>
                <p class="muted">{{ entry.count }} receipts</p>
              </div>
              <strong>{{ formatCurrency(entry.total) }}</strong>
            </div>
          </div>
        </article>

        <article class="card reports-ai-card">
          <div class="inline" style="justify-content: space-between">
            <h3>AI Review & Report</h3>
            <div class="inline">
              <button
                v-if="aiReport?.markdown"
                class="ghost"
                type="button"
                @click="downloadMarkdown"
                :disabled="loadingAi"
              >
                Download AI Report
              </button>
              <button class="primary" type="button" @click="generateAiReport" :disabled="loadingAi">
                {{ loadingAi ? 'Generating...' : aiReport ? 'Regenerate AI Review' : 'Generate AI Review' }}
              </button>
            </div>
          </div>

          <p v-if="aiReportReason" class="muted" style="margin-top: 0.5rem">{{ aiReportReason }}</p>

          <div v-if="aiReport" class="reports-ai-content">
            <article class="reports-ai-section">
              <h4>{{ aiReport.headline || 'Executive Overview' }}</h4>
              <p class="muted">{{ aiReport.executive_summary }}</p>
            </article>

            <article class="reports-ai-section">
              <h4>Trend Highlights</h4>
              <ul class="reports-ai-list">
                <li v-for="(line, idx) in aiReport.trend_highlights" :key="`trend-${idx}`">{{ line }}</li>
              </ul>
            </article>

            <article class="reports-ai-section">
              <h4>Anomalies</h4>
              <div v-if="aiReport.anomalies.length === 0" class="muted">No anomalies flagged.</div>
              <div v-else class="reports-ai-issue-list">
                <div v-for="(anomaly, idx) in aiReport.anomalies" :key="`anom-${idx}`" class="reports-ai-issue">
                  <div class="inline" style="justify-content: space-between">
                    <strong>{{ anomaly.title }}</strong>
                    <span class="pill" :class="severityClass(anomaly.severity)">{{ anomaly.severity }}</span>
                  </div>
                  <p class="muted">{{ anomaly.detail }}</p>
                </div>
              </div>
            </article>

            <article class="reports-ai-section">
              <h4>Recommendations</h4>
              <div class="reports-ai-issue-list">
                <div v-for="(rec, idx) in aiReport.recommendations" :key="`rec-${idx}`" class="reports-ai-issue">
                  <div class="inline" style="justify-content: space-between">
                    <strong>{{ rec.title }}</strong>
                    <span class="pill" :class="impactClass(rec.impact)">{{ rec.impact }}</span>
                  </div>
                  <p class="muted">{{ rec.detail }}</p>
                </div>
              </div>
            </article>

            <article class="reports-ai-section">
              <h4>Budget Signals</h4>
              <div class="reports-signal-grid">
                <div v-for="(signal, idx) in aiReport.budget_signals" :key="`signal-${idx}`" class="reports-signal-item">
                  <span>{{ signal.label }}</span>
                  <strong>{{ signal.value }}</strong>
                </div>
              </div>
            </article>

            <article class="reports-ai-section">
              <h4>Next Actions</h4>
              <ol class="reports-ai-list reports-ai-list-ordered">
                <li v-for="(line, idx) in aiReport.next_actions" :key="`next-${idx}`">{{ line }}</li>
              </ol>
            </article>
          </div>

          <div v-else class="reports-ai-empty muted">
            Run AI Review to generate an executive summary, anomaly scan, and action plan for this range.
          </div>
        </article>
      </section>
    </template>
  </section>
</template>

<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';

import { apiGet, apiPost } from '@/services/api';

type MonthlySeriesItem = {
  period: string;
  total: number;
  count: number;
};

type CategorySeriesItem = {
  category: string;
  total: number;
  count: number;
  share: number;
};

type MerchantSeriesItem = {
  merchant: string;
  total: number;
  count: number;
};

type OverviewSummary = {
  receipt_count: number;
  spend_total: number;
  average_receipt: number;
  largest_purchase: number;
  smallest_purchase: number;
  active_days: number;
  currency: string;
};

type AiIssue = {
  title: string;
  detail: string;
  severity: 'low' | 'medium' | 'high';
};

type AiRecommendation = {
  title: string;
  detail: string;
  impact: 'low' | 'medium' | 'high';
};

type AiSignal = {
  label: string;
  value: string;
};

type AiReport = {
  status: string;
  provider: string;
  model: string;
  generated_at?: string;
  reason?: string;
  headline: string;
  executive_summary: string;
  trend_highlights: string[];
  anomalies: AiIssue[];
  recommendations: AiRecommendation[];
  budget_signals: AiSignal[];
  next_actions: string[];
  markdown?: string;
};

type ReportsOverviewResponse = {
  window: {
    from: string | null;
    to: string | null;
  };
  summary: OverviewSummary;
  series: {
    monthly: MonthlySeriesItem[];
    categories: CategorySeriesItem[];
    merchants: MerchantSeriesItem[];
  };
  ai_report?: AiReport;
};

const presets = [
  { key: '30d', label: '30 Days' },
  { key: '90d', label: '90 Days' },
  { key: '365d', label: '12 Months' },
  { key: 'all', label: 'All Time' }
] as const;

type PresetKey = (typeof presets)[number]['key'];

const from = ref('');
const to = ref('');
const selectedPreset = ref<PresetKey>('90d');
const loadingOverview = ref(false);
const loadingAi = ref(false);
const error = ref('');
const note = ref('');

const summary = ref<OverviewSummary>({
  receipt_count: 0,
  spend_total: 0,
  average_receipt: 0,
  largest_purchase: 0,
  smallest_purchase: 0,
  active_days: 0,
  currency: 'USD'
});

const monthlySeries = ref<MonthlySeriesItem[]>([]);
const categorySeries = ref<CategorySeriesItem[]>([]);
const merchantSeries = ref<MerchantSeriesItem[]>([]);
const aiReport = ref<AiReport | null>(null);

const aiReportReason = computed(() => aiReport.value?.reason || '');
const chartMax = computed(() => {
  const max = monthlySeries.value.reduce((highest, item) => Math.max(highest, Number(item.total || 0)), 0);
  return max <= 0 ? 1 : max;
});

onMounted(async () => {
  setPreset('90d', false);
  await loadOverview();
});

function formatDateInput(value: Date): string {
  const year = value.getFullYear();
  const month = String(value.getMonth() + 1).padStart(2, '0');
  const day = String(value.getDate()).padStart(2, '0');
  return `${year}-${month}-${day}`;
}

function setPreset(preset: PresetKey, shouldLoad = true): void {
  selectedPreset.value = preset;

  if (preset === 'all') {
    from.value = '';
    to.value = '';
  } else {
    const days = preset === '30d' ? 30 : preset === '90d' ? 90 : 365;
    const end = new Date();
    end.setHours(0, 0, 0, 0);

    const start = new Date(end);
    start.setDate(start.getDate() - (days - 1));

    from.value = formatDateInput(start);
    to.value = formatDateInput(end);
  }

  if (shouldLoad) {
    void loadOverview();
  }
}

function buildRangeQuery(): string {
  const params = new URLSearchParams();
  if (from.value) {
    params.set('from', from.value);
  }
  if (to.value) {
    params.set('to', to.value);
  }
  return params.toString();
}

async function loadOverview(): Promise<void> {
  loadingOverview.value = true;
  error.value = '';
  note.value = '';

  try {
    const query = buildRangeQuery();
    const path = `/reports/overview${query ? `?${query}` : ''}`;
    const response = await apiGet<ReportsOverviewResponse>(path);

    summary.value = response.summary;
    monthlySeries.value = response.series.monthly || [];
    categorySeries.value = response.series.categories || [];
    merchantSeries.value = response.series.merchants || [];

    if (!aiReport.value && response.ai_report) {
      aiReport.value = response.ai_report;
    }
  } catch (err) {
    error.value = err instanceof Error ? err.message : 'Unable to load report overview';
  } finally {
    loadingOverview.value = false;
  }
}

async function generateAiReport(): Promise<void> {
  loadingAi.value = true;
  error.value = '';
  note.value = '';

  try {
    const response = await apiPost<ReportsOverviewResponse>('/reports/ai-review', {
      from: from.value || null,
      to: to.value || null
    });

    summary.value = response.summary;
    monthlySeries.value = response.series.monthly || [];
    categorySeries.value = response.series.categories || [];
    merchantSeries.value = response.series.merchants || [];
    aiReport.value = response.ai_report || null;

    note.value = aiReport.value?.status === 'success'
      ? 'AI report generated successfully.'
      : 'AI review generated with fallback insights.';
  } catch (err) {
    error.value = err instanceof Error ? err.message : 'Unable to generate AI review';
  } finally {
    loadingAi.value = false;
  }
}

function formatCurrency(value: number | null | undefined): string {
  const amount = Number(value ?? 0);
  const currency = (summary.value.currency || 'USD').toUpperCase();

  try {
    return new Intl.NumberFormat('en-US', {
      style: 'currency',
      currency,
      minimumFractionDigits: 2,
      maximumFractionDigits: 2
    }).format(Number.isFinite(amount) ? amount : 0);
  } catch {
    return `$${(Number.isFinite(amount) ? amount : 0).toFixed(2)} ${currency}`;
  }
}

function trendHeight(total: number): number {
  const safe = Number(total || 0);
  if (safe <= 0) {
    return 2;
  }

  return Math.max(6, (safe / chartMax.value) * 100);
}

function shortMonth(period: string): string {
  const parsed = new Date(`${period}-01T00:00:00Z`);
  if (Number.isNaN(parsed.getTime())) {
    return period;
  }

  return parsed.toLocaleDateString(undefined, { month: 'short', year: '2-digit' });
}

function severityClass(level: string): string {
  if (level === 'high') {
    return 'trace-status-failed';
  }
  if (level === 'medium') {
    return 'trace-status-default';
  }
  return 'trace-status-success';
}

function impactClass(level: string): string {
  if (level === 'high') {
    return 'trace-status-success';
  }
  if (level === 'medium') {
    return 'trace-status-default';
  }
  return 'trace-status-skipped';
}

function downloadCsv(): void {
  const query = buildRangeQuery();
  const apiBase = import.meta.env.VITE_API_BASE_URL || '/api';
  window.location.href = `${apiBase}/export/csv${query ? `?${query}` : ''}`;
  note.value = 'CSV export started.';
}

function downloadMarkdown(): void {
  if (!aiReport.value?.markdown) {
    return;
  }

  const blob = new Blob([aiReport.value.markdown], { type: 'text/markdown;charset=utf-8' });
  const url = URL.createObjectURL(blob);
  const link = document.createElement('a');
  link.href = url;

  const timestamp = new Date().toISOString().slice(0, 10);
  link.download = `onledge-ai-report-${timestamp}.md`;
  document.body.appendChild(link);
  link.click();
  document.body.removeChild(link);
  URL.revokeObjectURL(url);
}
</script>
