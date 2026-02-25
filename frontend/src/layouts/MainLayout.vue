<template>
  <div class="shell">
    <aside class="sidebar">
      <router-link class="brand" to="/app/dashboard">OnLedge</router-link>
      <nav class="menu">
        <router-link to="/app/dashboard">Dashboard</router-link>
        <router-link to="/app/capture">Capture</router-link>
        <router-link to="/app/receipts">Receipts</router-link>
        <router-link to="/app/reports">Reports</router-link>
        <router-link to="/app/rules">Rules</router-link>
        <router-link to="/app/settings">Settings</router-link>
      </nav>
      <button class="ghost" @click="logout">Logout</button>
    </aside>

    <main class="content">
      <slot />
    </main>
  </div>
</template>

<script setup lang="ts">
import { useRouter } from 'vue-router';

import { useAuthStore } from '@/stores/auth';

const auth = useAuthStore();
const router = useRouter();

async function logout() {
  await auth.logout();
  await router.push('/login');
}
</script>
