<template>
  <section class="page">
    <h1>Capture Receipt</h1>
    <p class="muted">Capture now. If offline, it queues automatically and syncs on reconnect.</p>

    <form class="card" @submit.prevent="submit" style="margin-top: 1rem">
      <div class="grid">
        <div>
          <label for="merchant">Merchant</label>
          <input id="merchant" v-model="form.merchant" required />
        </div>

        <div>
          <label for="total">Total</label>
          <input id="total" v-model.number="form.total" type="number" min="0" step="0.01" required />
        </div>

        <div>
          <label for="currency">Currency</label>
          <input id="currency" v-model="form.currency" maxlength="3" />
        </div>

        <div>
          <label for="purchasedAt">Purchase Date</label>
          <input id="purchasedAt" v-model="form.purchased_at" type="date" />
        </div>
      </div>

      <div style="margin-top: 1rem">
        <label for="category">Category</label>
        <input id="category" v-model="form.category" placeholder="e.g. Meals" />
      </div>

      <div style="margin-top: 1rem">
        <label for="tags">Tags (comma separated)</label>
        <input id="tags" v-model="tagsInput" placeholder="travel, client-a" />
      </div>

      <div style="margin-top: 1rem">
        <label for="notes">Notes</label>
        <textarea id="notes" v-model="form.notes"></textarea>
      </div>

      <div style="margin-top: 1rem">
        <label for="rawText">OCR / Raw Text</label>
        <textarea id="rawText" v-model="form.raw_text"></textarea>
      </div>

      <div style="margin-top: 1rem">
        <label for="file">Receipt File (optional)</label>
        <input id="file" type="file" accept=".pdf,image/png,image/jpeg" @change="onFileChange" />
      </div>

      <p v-if="message" class="success">{{ message }}</p>
      <p v-if="error" class="error">{{ error }}</p>

      <div style="margin-top: 1rem" class="inline">
        <button class="primary" :disabled="submitting">{{ submitting ? 'Saving...' : 'Save Receipt' }}</button>
        <button type="button" class="ghost" @click="reset">Reset</button>
      </div>
    </form>
  </section>
</template>

<script setup lang="ts">
import { reactive, ref } from 'vue';

import { useReceiptsStore } from '@/stores/receipts';

const receipts = useReceiptsStore();

const form = reactive({
  merchant: '',
  total: 0,
  currency: 'USD',
  purchased_at: '',
  category: '',
  notes: '',
  raw_text: ''
});

const tagsInput = ref('');
const file = ref<File | null>(null);
const submitting = ref(false);
const message = ref('');
const error = ref('');

function onFileChange(event: Event) {
  const target = event.target as HTMLInputElement;
  file.value = target.files?.[0] ?? null;
}

function reset() {
  form.merchant = '';
  form.total = 0;
  form.currency = 'USD';
  form.purchased_at = '';
  form.category = '';
  form.notes = '';
  form.raw_text = '';
  tagsInput.value = '';
  file.value = null;
}

async function submit() {
  submitting.value = true;
  error.value = '';
  message.value = '';

  try {
    const payload = {
      ...form,
      tags: tagsInput.value.split(',').map((tag) => tag.trim()).filter(Boolean)
    };

    await receipts.createReceipt(payload, file.value);
    message.value = navigator.onLine ? 'Receipt saved.' : 'Saved offline. It will sync automatically.';
    reset();
  } catch (err) {
    error.value = err instanceof Error ? err.message : 'Unable to save receipt';
  } finally {
    submitting.value = false;
  }
}
</script>
