<template>
  <transition name="slide-down">
    <div v-if="offline" class="offline-banner">
      You are offline. New receipts are queued and will sync when connection returns.
    </div>
  </transition>
</template>

<script setup lang="ts">
import { onMounted, onUnmounted, ref } from 'vue';

const offline = ref(!navigator.onLine);

function updateStatus() {
  offline.value = !navigator.onLine;
}

onMounted(() => {
  window.addEventListener('online', updateStatus);
  window.addEventListener('offline', updateStatus);
});

onUnmounted(() => {
  window.removeEventListener('online', updateStatus);
  window.removeEventListener('offline', updateStatus);
});
</script>
