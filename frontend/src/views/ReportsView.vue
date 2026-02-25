<template>
  <section class="page">
    <h1>Reports</h1>
    <p class="muted">Export receipts to CSV by date range.</p>

    <div class="card" style="margin-top: 1rem">
      <div class="grid">
        <div>
          <label for="from">From</label>
          <input id="from" type="date" v-model="from" />
        </div>

        <div>
          <label for="to">To</label>
          <input id="to" type="date" v-model="to" />
        </div>
      </div>

      <div style="margin-top: 1rem" class="inline">
        <button class="primary" @click="download">Download CSV</button>
      </div>

      <p v-if="note" class="success">{{ note }}</p>
    </div>
  </section>
</template>

<script setup lang="ts">
import { ref } from 'vue';

const from = ref('');
const to = ref('');
const note = ref('');
const apiBase = import.meta.env.VITE_API_BASE_URL || '/api';

function download() {
  const params = new URLSearchParams();
  if (from.value) {
    params.set('from', from.value);
  }
  if (to.value) {
    params.set('to', to.value);
  }

  const query = params.toString();
  window.location.href = `${apiBase}/export/csv${query ? `?${query}` : ''}`;
  note.value = 'CSV export started. If blocked, allow download popups.';
}
</script>
