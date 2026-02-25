<template>
  <div class="auth-shell">
    <div class="card auth-card">
      <BrandLockup compact />
      <h1>Login</h1>
      <p class="muted">Use your OnLedge account to access receipts and sync data.</p>

      <form @submit.prevent="submit">
        <div>
          <label for="email">Email</label>
          <input id="email" v-model="email" type="email" required autocomplete="email" />
        </div>

        <div style="margin-top: 0.75rem">
          <label for="password">Password</label>
          <input id="password" v-model="password" type="password" required autocomplete="current-password" />
        </div>

        <p v-if="error" class="error">{{ error }}</p>

        <div style="margin-top: 1rem" class="inline">
          <button class="primary" :disabled="submitting">{{ submitting ? 'Signing in...' : 'Login' }}</button>
          <router-link to="/forgot-password">Forgot password?</router-link>
        </div>
      </form>

      <div style="margin-top: 1.2rem">
        <p class="muted" style="margin: 0 0 0.5rem">Or continue with</p>
        <div class="inline">
          <button
            type="button"
            class="ghost"
            :disabled="!oauth.github"
            @click="startOauth('github')"
          >
            GitHub
          </button>
          <button
            type="button"
            class="ghost"
            :disabled="!oauth.discord"
            @click="startOauth('discord')"
          >
            Discord
          </button>
        </div>
      </div>

      <p style="margin-top: 1rem">
        No account? <router-link to="/register">Register</router-link>
      </p>
    </div>
  </div>
</template>

<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue';
import { useRouter } from 'vue-router';

import BrandLockup from '@/components/BrandLockup.vue';
import { apiGet } from '@/services/api';
import { useAuthStore } from '@/stores/auth';

const auth = useAuthStore();
const router = useRouter();

const email = ref('');
const password = ref('');
const error = ref('');
const submitting = ref(false);
const oauth = reactive({
  github: false,
  discord: false
});

async function loadOauthProviders() {
  try {
    const response = await apiGet<{ items: Array<{ provider: string; enabled: boolean }> }>('/auth/oauth/providers');
    for (const item of response.items) {
      if (item.provider === 'github') {
        oauth.github = Boolean(item.enabled);
      }
      if (item.provider === 'discord') {
        oauth.discord = Boolean(item.enabled);
      }
    }
  } catch {
    oauth.github = false;
    oauth.discord = false;
  }
}

async function submit() {
  submitting.value = true;
  error.value = '';

  try {
    await auth.login(email.value, password.value);
    await router.push('/app/dashboard');
  } catch (err) {
    error.value = err instanceof Error ? err.message : 'Unable to login';
  } finally {
    submitting.value = false;
  }
}

function startOauth(provider: 'github' | 'discord') {
  window.location.href = `/api/auth/oauth/${provider}/start`;
}

onMounted(() => {
  loadOauthProviders();
});
</script>
