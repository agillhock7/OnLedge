<template>
  <div class="brand-lockup" :class="{ compact }">
    <img class="brand-lockup__mark" src="/icon.svg" alt="OnLedge logo" />
    <div class="brand-lockup__text">
      <span class="brand-lockup__name">OnLedge</span>
      <span v-if="!compact" class="brand-lockup__tagline">{{ taglineText }}</span>
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed } from 'vue';

const props = defineProps<{
  compact?: boolean;
  tagline?: string;
}>();

const taglineText = computed(() => props.tagline || 'Capture receipts. Keep your books on ledge.');
</script>
