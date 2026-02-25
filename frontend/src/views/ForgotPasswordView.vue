<template>
  <div class="auth-shell">
    <div class="card auth-card">
      <BrandLockup compact />
      <h1>Forgot Password</h1>
      <p class="muted">Submit your email. The backend stores reset requests for future SMTP handling.</p>

      <form @submit.prevent="submit">
        <div>
          <label for="email">Email</label>
          <input id="email" v-model="email" type="email" required autocomplete="email" />
        </div>

        <p v-if="message" class="success">{{ message }}</p>
        <p v-if="error" class="error">{{ error }}</p>

        <div style="margin-top: 1rem" class="inline">
          <button class="primary" :disabled="submitting">{{ submitting ? 'Submitting...' : 'Submit' }}</button>
          <router-link to="/login">Back to login</router-link>
        </div>
      </form>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref } from 'vue';

import BrandLockup from '@/components/BrandLockup.vue';
import { useAuthStore } from '@/stores/auth';

const auth = useAuthStore();
const email = ref('');
const error = ref('');
const message = ref('');
const submitting = ref(false);

async function submit() {
  submitting.value = true;
  message.value = '';
  error.value = '';

  try {
    await auth.forgotPassword(email.value);
    message.value = 'Request accepted. If the account exists, reset flow can continue.';
  } catch (err) {
    error.value = err instanceof Error ? err.message : 'Unable to submit request';
  } finally {
    submitting.value = false;
  }
}
</script>
