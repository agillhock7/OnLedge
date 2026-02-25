<template>
  <section class="page">
    <h1>Dashboard</h1>
    <p class="muted">Quick status of your receipt ledger and sync queue.</p>

    <div class="grid" style="margin-top: 1rem">
      <article class="card">
        <h3>Total Receipts</h3>
        <p style="font-size: 2rem; margin: 0">{{ receipts.totalCount }}</p>
      </article>

      <article class="card">
        <h3>Total Spend</h3>
        <p style="font-size: 2rem; margin: 0">${{ receipts.totalAmount.toFixed(2) }}</p>
      </article>

      <article class="card">
        <h3>Sync State</h3>
        <p style="font-size: 1.15rem; margin: 0">{{ receipts.syncing ? 'Syncing queue...' : 'Idle' }}</p>
      </article>
    </div>
  </section>
</template>

<script setup lang="ts">
import { onMounted } from 'vue';

import { useReceiptsStore } from '@/stores/receipts';

const receipts = useReceiptsStore();

onMounted(() => {
  receipts.fetchReceipts();
});
</script>
