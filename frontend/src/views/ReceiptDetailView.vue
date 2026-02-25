<template>
  <section class="page" v-if="item">
    <header class="receipt-detail-head">
      <div>
        <p class="kicker">Receipt Record</p>
        <h1>{{ item.merchant || 'Untitled Receipt' }}</h1>
        <p class="muted">
          Purchased {{ item.purchased_at || 'unknown date' }}
          <span v-if="item.purchased_time">at {{ item.purchased_time }}</span>
          · Updated {{ item.updated_at ? new Date(item.updated_at).toLocaleString() : '-' }}
        </p>
      </div>

      <div class="receipt-detail-actions">
        <button class="ghost" type="button" @click="goBack">Back</button>
        <button class="secondary" type="button" @click="process" :disabled="processing || isOfflineDraft || saving">
          {{ processing ? 'Processing...' : 'Run AI Processing' }}
        </button>
        <button
          v-if="!editing"
          class="primary"
          type="button"
          @click="startEditing"
          :disabled="processing || saving || isOfflineDraft"
        >
          Edit Details
        </button>
        <template v-else>
          <button class="primary" type="button" @click="save" :disabled="saving || processing">
            {{ saving ? 'Saving...' : 'Save Changes' }}
          </button>
          <button class="ghost" type="button" @click="cancelEditing" :disabled="saving || processing">Cancel</button>
        </template>
        <button class="ghost" type="button" @click="remove" :disabled="isOfflineDraft || saving || processing">Delete</button>
      </div>
    </header>

    <p v-if="success" class="success" style="margin-top: 0.8rem">{{ success }}</p>
    <p v-if="error" class="error" style="margin-top: 0.8rem">{{ error }}</p>

    <div class="receipt-detail-layout" style="margin-top: 1rem">
      <article class="card receipt-image-card">
        <div class="inline" style="justify-content: space-between">
          <h3>Captured Image</h3>
          <a
            v-if="hasImage && !imageFailed"
            class="ghost receipt-image-action"
            :href="receiptImageUrl"
            target="_blank"
            rel="noopener noreferrer"
          >
            Open Full Size
          </a>
        </div>

        <div v-if="hasImage && !imageFailed" class="receipt-image-frame">
          <img :src="receiptImageUrl" alt="Adjusted receipt capture" class="receipt-image" @error="onImageError" />
        </div>
        <p v-else class="muted" style="margin: 0.5rem 0 0">
          No captured image available for this receipt yet.
        </p>
      </article>

      <article class="card">
        <h3>{{ editing ? 'Edit Key Details' : 'Key Details' }}</h3>

        <form v-if="editing" class="receipt-edit-form" @submit.prevent="save">
          <div class="receipt-edit-grid">
            <label>
              Merchant
              <input v-model="form.merchant" placeholder="Store name" />
            </label>
            <label>
              Receipt #
              <input v-model="form.receipt_number" placeholder="Receipt number" />
            </label>
            <label>
              Purchase Date
              <input v-model="form.purchased_at" type="date" />
            </label>
            <label>
              Time
              <input v-model="form.purchased_time" type="time" />
            </label>
            <label>
              Currency
              <input v-model="form.currency" maxlength="3" placeholder="USD" />
            </label>
            <label>
              Total
              <input v-model="form.total" type="number" step="0.01" placeholder="0.00" />
            </label>
            <label>
              Subtotal
              <input v-model="form.subtotal" type="number" step="0.01" placeholder="0.00" />
            </label>
            <label>
              Tax
              <input v-model="form.tax" type="number" step="0.01" placeholder="0.00" />
            </label>
            <label>
              Tip
              <input v-model="form.tip" type="number" step="0.01" placeholder="0.00" />
            </label>
            <label>
              Category
              <input v-model="form.category" placeholder="Category" />
            </label>
            <label>
              Payment Method
              <input v-model="form.payment_method" placeholder="Card / Cash / etc." />
            </label>
            <label>
              Card Last 4
              <input v-model="form.payment_last4" maxlength="4" placeholder="1234" />
            </label>
            <label class="receipt-field-wide">
              Merchant Address
              <input v-model="form.merchant_address" placeholder="Merchant address" />
            </label>
            <label class="receipt-field-wide">
              Tags (comma separated)
              <input v-model="form.tags" placeholder="groceries, dinner, tax-deductible" />
            </label>
            <label class="receipt-field-wide">
              Notes
              <textarea v-model="form.notes" placeholder="Add notes for this receipt"></textarea>
            </label>
          </div>

          <div class="receipt-line-items-edit" style="margin-top: 1rem">
            <div class="inline" style="justify-content: space-between">
              <h4>Line Items</h4>
              <button class="ghost" type="button" @click="addLineItem">Add Item</button>
            </div>

            <div v-if="form.line_items.length === 0" class="muted">No line items yet.</div>
            <div v-else class="receipt-line-items-grid">
              <div v-for="(line, index) in form.line_items" :key="index" class="receipt-line-edit-row">
                <input v-model="line.name" placeholder="Item name" />
                <input v-model="line.quantity" type="number" step="0.01" placeholder="Qty" />
                <input v-model="line.unit_price" type="number" step="0.01" placeholder="Unit" />
                <input v-model="line.total_price" type="number" step="0.01" placeholder="Total" />
                <input v-model="line.category" placeholder="Category" />
                <button class="ghost" type="button" @click="removeLineItem(index)">Remove</button>
              </div>
            </div>
          </div>

          <label style="margin-top: 1rem">
            Raw OCR Text
            <textarea v-model="form.raw_text" class="receipt-ocr-input" placeholder="Full OCR text"></textarea>
          </label>
        </form>

        <div v-else class="receipt-key-grid">
          <div class="receipt-key-item"><span>Merchant</span><strong>{{ item.merchant || '-' }}</strong></div>
          <div class="receipt-key-item"><span>Receipt #</span><strong>{{ item.receipt_number || '-' }}</strong></div>
          <div class="receipt-key-item"><span>Total</span><strong>{{ formatCurrency(item.total, item.currency || 'USD') }}</strong></div>
          <div class="receipt-key-item"><span>Subtotal</span><strong>{{ formatCurrency(item.subtotal, item.currency || 'USD') }}</strong></div>
          <div class="receipt-key-item"><span>Tax</span><strong>{{ formatCurrency(item.tax, item.currency || 'USD') }}</strong></div>
          <div class="receipt-key-item"><span>Tip</span><strong>{{ formatCurrency(item.tip, item.currency || 'USD') }}</strong></div>
          <div class="receipt-key-item"><span>Category</span><strong>{{ item.category || '-' }}</strong></div>
          <div class="receipt-key-item"><span>Tags</span><strong>{{ (item.tags || []).join(', ') || '-' }}</strong></div>
          <div class="receipt-key-item"><span>Merchant Address</span><strong>{{ item.merchant_address || '-' }}</strong></div>
          <div class="receipt-key-item"><span>Payment</span><strong>{{ paymentDisplay }}</strong></div>
          <div class="receipt-key-item"><span>AI Model</span><strong>{{ item.ai_model || '-' }}</strong></div>
          <div class="receipt-key-item">
            <span>AI Confidence</span>
            <strong>{{ item.ai_confidence !== undefined && item.ai_confidence !== null ? item.ai_confidence.toFixed(2) : '-' }}</strong>
          </div>
        </div>
      </article>
    </div>

    <article class="card" style="margin-top: 1rem">
      <h3>Line Items</h3>
      <div v-if="lineItems.length > 0" style="overflow-x: auto">
        <table class="table">
          <thead>
            <tr>
              <th>Item</th>
              <th>Qty</th>
              <th>Unit</th>
              <th>Total</th>
              <th>Category</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="(line, idx) in lineItems" :key="idx">
              <td>{{ line.name }}</td>
              <td>{{ line.quantity ?? '-' }}</td>
              <td>{{ line.unit_price !== null && line.unit_price !== undefined ? formatCurrency(line.unit_price, item.currency || 'USD') : '-' }}</td>
              <td>{{ line.total_price !== null && line.total_price !== undefined ? formatCurrency(line.total_price, item.currency || 'USD') : '-' }}</td>
              <td>{{ line.category || '-' }}</td>
            </tr>
          </tbody>
        </table>
      </div>
      <p v-else class="muted">No line items extracted yet.</p>
    </article>

    <article class="card" style="margin-top: 1rem">
      <h3>Processing Trace</h3>
      <div v-if="processingTrace.length === 0" class="muted">No processing trace yet.</div>
      <div v-else class="processing-trace-list">
        <div v-for="(entry, index) in processingTrace" :key="index" class="processing-trace-item">
          <div class="inline" style="justify-content: space-between">
            <strong>{{ String(entry.stage || 'stage') }}</strong>
            <span class="pill" :class="traceStatusClass(entry.status)">{{ String(entry.status || 'unknown') }}</span>
          </div>
          <p class="muted" style="margin: 0.25rem 0 0">{{ String(entry.reason || 'No additional details.') }}</p>
          <p v-if="Array.isArray(entry.fields_extracted)" class="muted" style="margin: 0.4rem 0 0">
            Fields: {{ entry.fields_extracted.join(', ') || 'none' }}
          </p>
        </div>
      </div>
    </article>

    <article class="card" style="margin-top: 1rem">
      <h3>Raw OCR Text</h3>
      <pre class="receipt-ocr-view">{{ item.raw_text || '-' }}</pre>
    </article>
  </section>

  <section class="page" v-else>
    <h1>Receipt not found</h1>
  </section>
</template>

<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';

import { useReceiptsStore, type Receipt } from '@/stores/receipts';

type EditableLineItem = {
  name: string;
  quantity: string;
  unit_price: string;
  total_price: string;
  category: string;
};

type EditForm = {
  merchant: string;
  merchant_address: string;
  receipt_number: string;
  purchased_at: string;
  purchased_time: string;
  currency: string;
  total: string;
  subtotal: string;
  tax: string;
  tip: string;
  category: string;
  payment_method: string;
  payment_last4: string;
  tags: string;
  notes: string;
  raw_text: string;
  line_items: EditableLineItem[];
};

const route = useRoute();
const router = useRouter();
const receipts = useReceiptsStore();

const item = ref<Receipt | null>(null);
const error = ref('');
const success = ref('');
const processing = ref(false);
const saving = ref(false);
const editing = ref(false);
const imageFailed = ref(false);

const form = ref<EditForm>(emptyForm());

const isOfflineDraft = computed(() => Boolean(item.value?.offline));
const lineItems = computed(() => item.value?.line_items ?? []);
const hasImage = computed(() => Boolean(item.value?.file_path));
const receiptImageUrl = computed(() => {
  if (!item.value || !item.value.file_path) {
    return '';
  }

  const baseUrl = import.meta.env.VITE_API_BASE_URL || '/api';
  const version = encodeURIComponent(item.value.updated_at || item.value.created_at || '');
  return `${baseUrl}/receipts/${item.value.id}/image?v=${version}`;
});

const paymentDisplay = computed(() => {
  if (!item.value) {
    return '-';
  }

  const method = item.value.payment_method || '-';
  const last4 = item.value.payment_last4 ? ` •••• ${item.value.payment_last4}` : '';
  return `${method}${last4}`.trim();
});

const processingTrace = computed<Array<Record<string, unknown>>>(() => {
  const trace = item.value?.processing_explanation;
  return Array.isArray(trace) ? trace.filter((entry): entry is Record<string, unknown> => typeof entry === 'object' && entry !== null) : [];
});

watch(
  () => receiptImageUrl.value,
  () => {
    imageFailed.value = false;
  }
);

onMounted(async () => {
  await loadReceipt();
});

async function loadReceipt(): Promise<void> {
  const id = String(route.params.id);
  item.value = await receipts.getById(id);
  if (item.value) {
    syncFormWithItem(item.value);
  }
}

function emptyForm(): EditForm {
  return {
    merchant: '',
    merchant_address: '',
    receipt_number: '',
    purchased_at: '',
    purchased_time: '',
    currency: 'USD',
    total: '',
    subtotal: '',
    tax: '',
    tip: '',
    category: '',
    payment_method: '',
    payment_last4: '',
    tags: '',
    notes: '',
    raw_text: '',
    line_items: []
  };
}

function syncFormWithItem(receipt: Receipt): void {
  form.value = {
    merchant: receipt.merchant || '',
    merchant_address: receipt.merchant_address || '',
    receipt_number: receipt.receipt_number || '',
    purchased_at: receipt.purchased_at || '',
    purchased_time: receipt.purchased_time || '',
    currency: (receipt.currency || 'USD').toUpperCase(),
    total: toInputNumber(receipt.total),
    subtotal: toInputNumber(receipt.subtotal),
    tax: toInputNumber(receipt.tax),
    tip: toInputNumber(receipt.tip),
    category: receipt.category || '',
    payment_method: receipt.payment_method || '',
    payment_last4: receipt.payment_last4 || '',
    tags: (receipt.tags || []).join(', '),
    notes: receipt.notes || '',
    raw_text: receipt.raw_text || '',
    line_items: (receipt.line_items || []).map((line) => ({
      name: line.name || '',
      quantity: toInputNumber(line.quantity ?? null),
      unit_price: toInputNumber(line.unit_price ?? null),
      total_price: toInputNumber(line.total_price ?? null),
      category: line.category || ''
    }))
  };
}

function toInputNumber(value: number | null | undefined): string {
  if (value === null || value === undefined || Number.isNaN(value)) {
    return '';
  }
  return String(value);
}

function parseOptionalNumber(value: string): number | null {
  const trimmed = value.trim();
  if (trimmed === '') {
    return null;
  }

  const parsed = Number(trimmed);
  return Number.isFinite(parsed) ? parsed : null;
}

function parseTags(value: string): string[] {
  return value
    .split(',')
    .map((tag) => tag.trim())
    .filter((tag) => tag.length > 0);
}

function formatCurrency(value: number | null | undefined, currency: string): string {
  const safeValue = Number(value ?? 0);
  const safeCurrency = (currency || 'USD').toUpperCase();

  try {
    return new Intl.NumberFormat('en-US', {
      style: 'currency',
      currency: safeCurrency,
      minimumFractionDigits: 2,
      maximumFractionDigits: 2
    }).format(Number.isFinite(safeValue) ? safeValue : 0);
  } catch {
    return `$${(Number.isFinite(safeValue) ? safeValue : 0).toFixed(2)} ${safeCurrency}`;
  }
}

function addLineItem(): void {
  form.value.line_items.push({
    name: '',
    quantity: '',
    unit_price: '',
    total_price: '',
    category: ''
  });
}

function removeLineItem(index: number): void {
  form.value.line_items.splice(index, 1);
}

function startEditing(): void {
  if (!item.value) {
    return;
  }

  syncFormWithItem(item.value);
  editing.value = true;
  success.value = '';
  error.value = '';
}

function cancelEditing(): void {
  if (item.value) {
    syncFormWithItem(item.value);
  }
  editing.value = false;
  error.value = '';
}

async function save(): Promise<void> {
  if (!item.value) {
    return;
  }

  saving.value = true;
  error.value = '';
  success.value = '';

  try {
    const payload: Record<string, unknown> = {
      merchant: form.value.merchant.trim(),
      merchant_address: form.value.merchant_address.trim(),
      receipt_number: form.value.receipt_number.trim(),
      purchased_at: form.value.purchased_at,
      purchased_time: form.value.purchased_time,
      currency: form.value.currency.trim().toUpperCase() || 'USD',
      total: parseOptionalNumber(form.value.total),
      subtotal: parseOptionalNumber(form.value.subtotal),
      tax: parseOptionalNumber(form.value.tax),
      tip: parseOptionalNumber(form.value.tip),
      category: form.value.category.trim(),
      payment_method: form.value.payment_method.trim(),
      payment_last4: form.value.payment_last4.trim(),
      tags: parseTags(form.value.tags),
      notes: form.value.notes.trim(),
      raw_text: form.value.raw_text,
      line_items: form.value.line_items
        .map((line) => ({
          name: line.name.trim(),
          quantity: parseOptionalNumber(line.quantity),
          unit_price: parseOptionalNumber(line.unit_price),
          total_price: parseOptionalNumber(line.total_price),
          category: line.category.trim() || null
        }))
        .filter((line) => line.name !== '')
    };

    await receipts.updateReceipt(item.value.id, payload);
    await loadReceipt();

    editing.value = false;
    success.value = 'Receipt details saved.';
  } catch (err) {
    error.value = err instanceof Error ? err.message : 'Unable to save receipt';
  } finally {
    saving.value = false;
  }
}

async function process(): Promise<void> {
  if (!item.value) {
    return;
  }

  processing.value = true;
  error.value = '';
  success.value = '';

  try {
    await receipts.processReceipt(item.value.id);
    await loadReceipt();
    success.value = 'AI processing complete.';
  } catch (err) {
    error.value = err instanceof Error ? err.message : 'Unable to process receipt';
  } finally {
    processing.value = false;
  }
}

async function remove(): Promise<void> {
  if (!item.value) {
    return;
  }

  try {
    await receipts.deleteReceipt(item.value.id);
    await router.push('/app/receipts');
  } catch (err) {
    error.value = err instanceof Error ? err.message : 'Unable to delete receipt';
  }
}

async function goBack(): Promise<void> {
  await router.push('/app/receipts');
}

function onImageError(): void {
  imageFailed.value = true;
}

function traceStatusClass(status: unknown): string {
  const value = String(status || '').toLowerCase();
  if (value === 'success') {
    return 'trace-status-success';
  }
  if (value === 'failed') {
    return 'trace-status-failed';
  }
  if (value === 'skipped') {
    return 'trace-status-skipped';
  }
  return 'trace-status-default';
}
</script>
