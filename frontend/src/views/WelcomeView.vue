<template>
  <div class="hero">
    <div class="hero-inner card">
      <BrandLockup />
      <p class="kicker">Built for founders, teams, and finance ops</p>
      <h1>Receipts, Search, Export. No chaos.</h1>
      <p>
        OnLedge captures receipts online or offline, applies explainable rules, and exports clean reports.
      </p>
      <div class="hero-pills">
        <span>Camera-first capture</span>
        <span>Offline queue + sync</span>
        <span>PostgreSQL full-text search</span>
      </div>
      <div class="inline" style="justify-content: center; margin-top: 1rem">
        <router-link to="/login"><button class="primary">Login</button></router-link>
        <router-link to="/register"><button class="secondary">Create Account</button></router-link>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import BrandLockup from '@/components/BrandLockup.vue';
</script>
