<template>
  <OfflineBanner />
  <router-view v-slot="{ Component, route }">
    <MainLayout v-if="route.path.startsWith('/app')">
      <component :is="Component" />
    </MainLayout>
    <component :is="Component" v-else />
  </router-view>
</template>

<script setup lang="ts">
import { onMounted, onUnmounted } from 'vue';

import OfflineBanner from '@/components/OfflineBanner.vue';
import MainLayout from '@/layouts/MainLayout.vue';
import { useReceiptsStore } from '@/stores/receipts';

const receiptsStore = useReceiptsStore();

function syncOnReconnect() {
  receiptsStore.syncQueue();
}

onMounted(() => {
  window.addEventListener('online', syncOnReconnect);
  receiptsStore.syncQueue();
});

onUnmounted(() => {
  window.removeEventListener('online', syncOnReconnect);
});
</script>
