<template>
  <div class="shell">
    <a class="skip-link" href="#app-content">Skip to content</a>

    <aside class="sidebar">
      <router-link class="brand-home" to="/app/dashboard">
        <BrandLockup compact />
      </router-link>
      <p class="sidebar-copy">Receipt intelligence for modern operators.</p>
      <nav class="menu">
        <router-link to="/app/dashboard">Dashboard</router-link>
        <router-link to="/app/capture">Capture</router-link>
        <router-link to="/app/receipts">Receipts</router-link>
        <router-link to="/app/reports">Reports</router-link>
        <router-link to="/app/rules">Rules</router-link>
        <router-link to="/app/settings">Settings</router-link>
      </nav>
      <div class="sidebar-footer">
        <p class="sidebar-user">{{ auth.user?.email || 'Signed in' }}</p>
        <p class="sidebar-role">{{ auth.user?.role || 'user' }}</p>
        <button class="ghost" @click="logout">Logout</button>
      </div>
    </aside>

    <header class="mobile-topbar">
      <router-link class="mobile-brand" to="/app/dashboard" aria-label="Go to dashboard">
        <BrandLockup compact />
      </router-link>

      <div class="mobile-topbar-actions">
        <router-link class="mobile-chip" :class="{ active: isActive('/app/rules') }" to="/app/rules">
          Rules
        </router-link>
        <button class="ghost mobile-logout" @click="logout" aria-label="Log out">Logout</button>
      </div>
    </header>

    <main id="app-content" class="content">
      <slot />
    </main>

    <nav class="mobile-nav" aria-label="Primary navigation">
      <router-link class="mobile-nav-link" :class="{ 'is-active': isActive('/app/dashboard') }" to="/app/dashboard">
        <svg viewBox="0 0 24 24" aria-hidden="true">
          <path d="M3 11.5 12 4l9 7.5V20a1 1 0 0 1-1 1h-5.5v-6h-5v6H4a1 1 0 0 1-1-1z" />
        </svg>
        <span>Home</span>
      </router-link>

      <router-link class="mobile-nav-link" :class="{ 'is-active': isActive('/app/receipts') }" to="/app/receipts">
        <svg viewBox="0 0 24 24" aria-hidden="true">
          <path d="M6 3h12a1 1 0 0 1 1 1v17l-2.5-1.5L14 21l-2.5-1.5L9 21l-2.5-1.5L4 21V4a1 1 0 0 1 1-1z" />
          <path d="M8 8h8M8 12h8M8 16h6" />
        </svg>
        <span>Receipts</span>
      </router-link>

      <router-link class="mobile-nav-capture" :class="{ 'is-active': isActive('/app/capture') }" to="/app/capture" aria-label="Capture receipt">
        <svg viewBox="0 0 24 24" aria-hidden="true">
          <path d="M4 8h3l1.4-2h7.2L17 8h3a1 1 0 0 1 1 1v10a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V9a1 1 0 0 1 1-1z" />
          <circle cx="12" cy="14" r="3.6" />
        </svg>
        <span>Capture</span>
      </router-link>

      <router-link class="mobile-nav-link" :class="{ 'is-active': isActive('/app/reports') }" to="/app/reports">
        <svg viewBox="0 0 24 24" aria-hidden="true">
          <path d="M5 20V9M12 20V4M19 20v-6" />
          <path d="M3 20h18" />
        </svg>
        <span>Reports</span>
      </router-link>

      <router-link class="mobile-nav-link" :class="{ 'is-active': isActive('/app/settings') }" to="/app/settings">
        <svg viewBox="0 0 24 24" aria-hidden="true">
          <path d="M12 8.2a3.8 3.8 0 1 0 0 7.6 3.8 3.8 0 0 0 0-7.6z" />
          <path d="M19.4 12.9a7.8 7.8 0 0 0 .1-.9 7.8 7.8 0 0 0-.1-.9l2-1.6-1.9-3.2-2.5 1a7.6 7.6 0 0 0-1.6-.9l-.4-2.7H9l-.4 2.7a7.6 7.6 0 0 0-1.6.9l-2.5-1L2.6 9.5l2 1.6a7.8 7.8 0 0 0-.1.9c0 .3 0 .6.1.9l-2 1.6 1.9 3.2 2.5-1c.5.4 1 .7 1.6.9l.4 2.7h4.2l.4-2.7c.6-.2 1.1-.5 1.6-.9l2.5 1 1.9-3.2z" />
        </svg>
        <span>Settings</span>
      </router-link>
    </nav>
  </div>
</template>

<script setup lang="ts">
import { useRoute, useRouter } from 'vue-router';

import BrandLockup from '@/components/BrandLockup.vue';
import { useAuthStore } from '@/stores/auth';

const auth = useAuthStore();
const route = useRoute();
const router = useRouter();

function isActive(prefix: string): boolean {
  return route.path === prefix || route.path.startsWith(`${prefix}/`);
}

async function logout() {
  await auth.logout();
  await router.push('/login');
}
</script>
