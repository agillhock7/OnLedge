<template>
  <section class="page">
    <h1>Rules</h1>
    <p class="muted">Define rule conditions/actions as JSON for processing explainability.</p>

    <form class="card" @submit.prevent="createRule" style="margin-top: 1rem">
      <div class="grid">
        <div>
          <label for="name">Rule Name</label>
          <input id="name" v-model="name" required />
        </div>

        <div>
          <label for="priority">Priority</label>
          <input id="priority" v-model.number="priority" type="number" min="1" />
        </div>
      </div>

      <div style="margin-top: 1rem">
        <label for="conditions">Conditions JSON</label>
        <textarea id="conditions" v-model="conditions"></textarea>
      </div>

      <div style="margin-top: 1rem">
        <label for="actions">Actions JSON</label>
        <textarea id="actions" v-model="actions"></textarea>
      </div>

      <div style="margin-top: 1rem" class="inline">
        <button class="primary" :disabled="submitting">{{ submitting ? 'Saving...' : 'Create Rule' }}</button>
      </div>
      <p v-if="error" class="error">{{ error }}</p>
    </form>

    <div class="card" style="margin-top: 1rem">
      <h3>Existing Rules</h3>
      <table class="table">
        <thead>
          <tr>
            <th>Name</th>
            <th>Priority</th>
            <th>Active</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="rule in rules" :key="rule.id">
            <td>{{ rule.name }}</td>
            <td>{{ rule.priority }}</td>
            <td>{{ rule.is_active ? 'Yes' : 'No' }}</td>
            <td><button class="ghost" @click="removeRule(rule.id)">Delete</button></td>
          </tr>
        </tbody>
      </table>
    </div>
  </section>
</template>

<script setup lang="ts">
import { onMounted, ref } from 'vue';

import { apiDelete, apiGet, apiPost } from '@/services/api';

type Rule = {
  id: number;
  name: string;
  priority: number;
  is_active: boolean;
};

type RuleListResponse = {
  items: Rule[];
};

const rules = ref<Rule[]>([]);
const name = ref('');
const priority = ref(100);
const conditions = ref('{\n  "all": [\n    { "field": "merchant", "operator": "contains", "value": "uber" }\n  ]\n}');
const actions = ref('{\n  "set": { "category": "Travel" },\n  "append_tags": ["rideshare"]\n}');
const error = ref('');
const submitting = ref(false);

async function loadRules() {
  const response = await apiGet<RuleListResponse>('/rules');
  rules.value = response.items;
}

async function createRule() {
  submitting.value = true;
  error.value = '';

  try {
    await apiPost('/rules', {
      name: name.value,
      priority: priority.value,
      is_active: true,
      conditions: JSON.parse(conditions.value),
      actions: JSON.parse(actions.value)
    });

    name.value = '';
    await loadRules();
  } catch (err) {
    error.value = err instanceof Error ? err.message : 'Unable to create rule';
  } finally {
    submitting.value = false;
  }
}

async function removeRule(id: number) {
  await apiDelete(`/rules/${id}`);
  await loadRules();
}

onMounted(() => {
  loadRules();
});
</script>
