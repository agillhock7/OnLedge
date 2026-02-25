<template>
  <section class="page">
    <h1>Settings</h1>
    <p class="muted">Environment information and deployment notes for this client build.</p>

    <article class="card" style="margin-top: 1rem">
      <h3>Runtime</h3>
      <p><strong>API Base:</strong> {{ apiBase }}</p>
      <p><strong>Online:</strong> {{ online ? 'Yes' : 'No' }}</p>
      <p><strong>PWA:</strong> Installed service worker with auto-update registration.</p>
    </article>

    <article class="card" style="margin-top: 1rem">
      <h3>Server Constraints</h3>
      <p class="muted">
        cPanel deploy copies files only. Build frontend locally and commit `deploy/public_html` artifacts before pushing.
      </p>
    </article>
  </section>
</template>

<script setup lang="ts">
import { ref } from 'vue';

const apiBase = import.meta.env.VITE_API_BASE_URL || '/api';
const online = ref(navigator.onLine);
</script>
