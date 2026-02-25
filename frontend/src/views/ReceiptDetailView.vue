<template>
  <section class="page" v-if="item">
    <div class="inline" style="justify-content: space-between">
      <div>
        <h1>{{ item.merchant || 'Receipt Detail' }}</h1>
        <p class="muted">{{ item.purchased_at || 'No purchase date' }}</p>
      </div>
      <div class="inline">
        <button class="secondary" @click="process" :disabled="processing || isOfflineDraft">
          {{ processing ? 'Processing...' : 'Run Processing Stub' }}
        </button>
        <button class="ghost" @click="remove" :disabled="isOfflineDraft">Delete</button>
      </div>
    </div>

    <div class="grid" style="margin-top: 1rem">
      <article class="card">
        <h3>Summary</h3>
        <p><strong>Total:</strong> ${{ Number(item.total ?? 0).toFixed(2) }} {{ item.currency || 'USD' }}</p>
        <p><strong>Category:</strong> {{ item.category || '-' }}</p>
        <p><strong>Tags:</strong> {{ (item.tags || []).join(', ') || '-' }}</p>
      </article>

      <article class="card">
        <h3>Notes</h3>
        <p class="muted">{{ item.notes || '-' }}</p>
      </article>
    </div>

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
