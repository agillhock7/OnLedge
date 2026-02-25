<template>
  <div class="auth-shell">
    <div class="card auth-card">
      <BrandLockup compact />
      <h1>Register</h1>
      <p class="muted">Create an account to keep your receipts searchable and exportable.</p>

      <form @submit.prevent="submit">
        <div>
          <label for="email">Email</label>
          <input id="email" v-model="email" type="email" required autocomplete="email" />
        </div>

        <div style="margin-top: 0.75rem">
          <label for="password">Password</label>
          <input id="password" v-model="password" type="password" required minlength="8" autocomplete="new-password" />
        </div>

        <p v-if="error" class="error">{{ error }}</p>

        <div style="margin-top: 1rem" class="inline">
          <button class="primary" :disabled="submitting">{{ submitting ? 'Creating...' : 'Create Account' }}</button>
          <router-link to="/login">Back to login</router-link>
        </div>
      </form>

      <p class="muted" style="margin-top: 0.8rem">
        Prefer social login? <router-link to="/login">Use GitHub or Discord from login.</router-link>
      </p>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref } from 'vue';
import { useRouter } from 'vue-router';

import BrandLockup from '@/components/BrandLockup.vue';
import { useAuthStore } from '@/stores/auth';

const auth = useAuthStore();
const router = useRouter();

const email = ref('');
const password = ref('');
const error = ref('');
const submitting = ref(false);

async function submit() {
  submitting.value = true;
  error.value = '';

  try {
    await auth.register(email.value, password.value);
    await router.push('/app/dashboard');
  } catch (err) {
    error.value = err instanceof Error ? err.message : 'Unable to register';
  } finally {
    submitting.value = false;
  }
}
</script>
