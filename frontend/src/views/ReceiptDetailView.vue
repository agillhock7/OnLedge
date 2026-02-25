<template>
  <section class="page" v-if="item">
    <div class="inline" style="justify-content: space-between">
      <div>
        <h1>{{ item.merchant || 'Receipt Detail' }}</h1>
        <p class="muted">{{ item.purchased_at || 'No purchase date' }}</p>
      </div>
      <div class="inline">
        <button class="secondary" @click="process" :disabled="processing || isOfflineDraft">
          {{ processing ? 'Processing...' : 'Run AI Processing' }}
        </button>
        <button class="ghost" @click="remove" :disabled="isOfflineDraft">Delete</button>
      </div>
    </div>

    <div class="grid" style="margin-top: 1rem">
      <article class="card">
        <h3>Summary</h3>
        <p><strong>Merchant:</strong> {{ item.merchant || '-' }}</p>
        <p><strong>Receipt #:</strong> {{ item.receipt_number || '-' }}</p>
        <p><strong>Date/Time:</strong> {{ item.purchased_at || '-' }} {{ item.purchased_time || '' }}</p>
        <p><strong>Total:</strong> ${{ Number(item.total ?? 0).toFixed(2) }} {{ item.currency || 'USD' }}</p>
        <p><strong>Subtotal:</strong> ${{ Number(item.subtotal ?? 0).toFixed(2) }}</p>
        <p><strong>Tax:</strong> ${{ Number(item.tax ?? 0).toFixed(2) }}</p>
        <p><strong>Tip:</strong> ${{ Number(item.tip ?? 0).toFixed(2) }}</p>
        <p><strong>Category:</strong> {{ item.category || '-' }}</p>
        <p><strong>Tags:</strong> {{ (item.tags || []).join(', ') || '-' }}</p>
      </article>

      <article class="card">
        <h3>Extracted Metadata</h3>
        <p><strong>Address:</strong> {{ item.merchant_address || '-' }}</p>
        <p><strong>Payment:</strong> {{ item.payment_method || '-' }} <span v-if="item.payment_last4">•••• {{ item.payment_last4 }}</span></p>
        <p><strong>AI Model:</strong> {{ item.ai_model || '-' }}</p>
        <p><strong>AI Confidence:</strong> {{ item.ai_confidence !== undefined && item.ai_confidence !== null ? item.ai_confidence.toFixed(2) : '-' }}</p>
        <p><strong>Processed At:</strong> {{ item.processed_at || '-' }}</p>
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
              <td>{{ line.unit_price !== null && line.unit_price !== undefined ? `$${Number(line.unit_price).toFixed(2)}` : '-' }}</td>
              <td>{{ line.total_price !== null && line.total_price !== undefined ? `$${Number(line.total_price).toFixed(2)}` : '-' }}</td>
              <td>{{ line.category || '-' }}</td>
            </tr>
          </tbody>
        </table>
      </div>
      <p v-else class="muted">No line items extracted yet.</p>
    </article>

    <article class="card" style="margin-top: 1rem">
      <h3>Notes</h3>
      <p class="muted">{{ item.notes || '-' }}</p>
    </article>

    <article class="card" style="margin-top: 1rem">
      <h3>Raw OCR Text</h3>
      <pre style="white-space: pre-wrap">{{ item.raw_text || '-' }}</pre>
    </article>

    <article class="card" style="margin-top: 1rem">
      <h3>Processing Explanation</h3>
      <pre style="white-space: pre-wrap">{{ prettyExplanation }}</pre>
    </article>

    <p v-if="error" class="error">{{ error }}</p>
  </section>

  <section class="page" v-else>
    <h1>Receipt not found</h1>
  </section>
</template>

<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';

import { useReceiptsStore, type Receipt } from '@/stores/receipts';

const route = useRoute();
const router = useRouter();
const receipts = useReceiptsStore();

const item = ref<Receipt | null>(null);
const error = ref('');
const processing = ref(false);

const isOfflineDraft = computed(() => Boolean(item.value?.offline));
const prettyExplanation = computed(() => JSON.stringify(item.value?.processing_explanation ?? [], null, 2));
const lineItems = computed(() => item.value?.line_items ?? []);

onMounted(async () => {
  const id = String(route.params.id);
  item.value = await receipts.getById(id);
});

async function process() {
  if (!item.value) {
    return;
  }

  processing.value = true;
  error.value = '';

  try {
    await receipts.processReceipt(item.value.id);
    item.value = await receipts.getById(item.value.id);
  } catch (err) {
    error.value = err instanceof Error ? err.message : 'Unable to process receipt';
  } finally {
    processing.value = false;
  }
}

async function remove() {
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
</script>
