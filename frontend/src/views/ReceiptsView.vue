<template>
  <section class="page">
    <div class="inline" style="justify-content: space-between; align-items: flex-end">
      <div>
        <h1>Receipts</h1>
        <p class="muted">Search, inspect, and manage receipts.</p>
      </div>
      <router-link to="/app/capture"><button class="secondary">Capture New</button></router-link>
    </div>

    <div class="card" style="margin-top: 1rem">
      <label for="search">Search</label>
      <input id="search" v-model="query" placeholder="Merchant, notes, category..." @input="runSearch" />
    </div>

    <p v-if="receipts.error" class="error">{{ receipts.error }}</p>

    <div class="card" style="margin-top: 1rem; overflow-x: auto">
      <table class="table">
        <thead>
          <tr>
            <th>Merchant</th>
            <th>Total</th>
            <th>Date</th>
            <th>Category</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="item in filtered" :key="item.id">
            <td>
              <strong>{{ item.merchant || 'Unknown' }}</strong>
              <div v-if="item.offline" class="muted">Offline draft</div>
            </td>
            <td>${{ Number(item.total ?? 0).toFixed(2) }}</td>
            <td>{{ item.purchased_at || '-' }}</td>
            <td>{{ item.category || '-' }}</td>
            <td>
              <router-link :to="`/app/receipts/${item.id}`">Open</router-link>
            </td>
          </tr>
        </tbody>
      </table>
    </div>
  </section>
</template>

<script setup lang="ts">
import { onMounted, ref } from 'vue';

import type { Receipt } from '@/stores/receipts';
import { useReceiptsStore } from '@/stores/receipts';

const receipts = useReceiptsStore();
const filtered = ref<Receipt[]>([]);
const query = ref('');

onMounted(async () => {
  await receipts.fetchReceipts();
  filtered.value = receipts.items;
});

async function runSearch() {
  filtered.value = await receipts.search(query.value);
}
</script>
