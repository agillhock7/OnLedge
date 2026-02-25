<template>
  <section class="page rules-page">
    <header class="rules-head">
      <div>
        <p class="kicker">Automation Studio</p>
        <h1>Smart Rules</h1>
        <p class="muted">Create no-code automations that categorize receipts, add tags, and standardize notes automatically.</p>
      </div>
      <div class="rules-head-stats">
        <div class="rules-stat-chip">
          <strong>{{ rules.length }}</strong>
          <span>Total Rules</span>
        </div>
        <div class="rules-stat-chip">
          <strong>{{ activeRuleCount }}</strong>
          <span>Active</span>
        </div>
      </div>
    </header>

    <article class="card rules-guide" style="margin-top: 1rem">
      <h3>How Rules Work</h3>
      <div class="rules-guide-grid">
        <div class="rules-guide-item">
          <strong>1. Choose conditions</strong>
          <p class="muted">Tell OnLedge when a rule should run (merchant, amount, category, tags, and more).</p>
        </div>
        <div class="rules-guide-item">
          <strong>2. Define actions</strong>
          <p class="muted">Set a category, append tags, and add standardized notes instantly.</p>
        </div>
        <div class="rules-guide-item">
          <strong>3. Set priority</strong>
          <p class="muted">Lower numbers run first. Start broad at the bottom, specific rules at the top.</p>
        </div>
      </div>
    </article>

    <article class="card rules-template-bank" style="margin-top: 1rem">
      <div class="inline" style="justify-content: space-between">
        <h3>Quick Start Templates</h3>
        <span class="muted">Tap to prefill the builder</span>
      </div>
      <div class="rules-template-grid">
        <button v-for="template in templates" :key="template.id" class="rules-template-card" type="button" @click="applyTemplate(template)">
          <strong>{{ template.title }}</strong>
          <p class="muted">{{ template.description }}</p>
        </button>
      </div>
    </article>

    <form class="card rules-builder" style="margin-top: 1rem" @submit.prevent="saveRule">
      <div class="inline" style="justify-content: space-between">
        <h3>{{ editingRuleId ? 'Edit Rule' : 'Create Rule' }}</h3>
        <button v-if="editingRuleId" type="button" class="ghost" @click="resetBuilder">Cancel Edit</button>
      </div>

      <div class="rules-builder-grid">
        <label>
          Rule Name
          <input v-model="formName" placeholder="Example: Auto-categorize rideshare" required />
        </label>
        <label>
          Priority (lower runs first)
          <input v-model.number="formPriority" type="number" min="1" max="999" />
        </label>
      </div>

      <div class="rules-active-row">
        <label class="rules-switch">
          <input v-model="formActive" type="checkbox" />
          <span class="rules-switch-track"></span>
          <span>{{ formActive ? 'Active rule' : 'Rule paused' }}</span>
        </label>
      </div>

      <section class="rules-section">
        <div class="inline" style="justify-content: space-between">
          <h4>When should this rule run?</h4>
          <button class="ghost" type="button" @click="addCondition">+ Add Condition</button>
        </div>

        <div class="rules-match-mode" role="group" aria-label="Condition matching mode">
          <button type="button" :class="{ active: matchMode === 'all' }" @click="matchMode = 'all'">Match All</button>
          <button type="button" :class="{ active: matchMode === 'any' }" @click="matchMode = 'any'">Match Any</button>
        </div>

        <div v-if="conditions.length === 0" class="muted">Add at least one condition.</div>

        <div v-for="(condition, index) in conditions" :key="condition.id" class="rules-condition-row">
          <label>
            Field
            <select v-model="condition.field" @change="onConditionFieldChange(condition)">
              <option v-for="field in fieldOptions" :key="field.key" :value="field.key">{{ field.label }}</option>
            </select>
          </label>

          <label>
            Operator
            <select v-model="condition.operator">
              <option v-for="operator in operatorsForField(condition.field)" :key="operator.key" :value="operator.key">
                {{ operator.label }}
              </option>
            </select>
          </label>

          <label class="rules-condition-value">
            Value
            <input
              v-model="condition.value"
              :placeholder="valuePlaceholder(condition.field, condition.operator)"
              :type="inputTypeForField(condition.field)"
            />
          </label>

          <button class="ghost rules-remove-condition" type="button" @click="removeCondition(index)" :disabled="conditions.length <= 1">
            Remove
          </button>
        </div>
      </section>

      <section class="rules-section">
        <h4>What should happen?</h4>
        <div class="rules-actions-grid">
          <label>
            Set Category
            <input v-model="actionCategory" list="rule-category-suggestions" placeholder="Travel, Meals, Office, Utilities" />
            <datalist id="rule-category-suggestions">
              <option v-for="category in categorySuggestions" :key="category" :value="category" />
            </datalist>
          </label>

          <label>
            Tags
            <input v-model="actionTags" placeholder="rideshare, recurring, team-lunch" />
          </label>

          <label>
            Tag Behavior
            <select v-model="tagMode">
              <option value="append">Append to existing tags</option>
              <option value="replace">Replace existing tags</option>
            </select>
          </label>

          <label class="rules-field-wide">
            Set Notes
            <textarea v-model="actionNotes" placeholder="Optional note to apply when this rule matches"></textarea>
          </label>
        </div>
      </section>

      <section class="rules-section rules-preview">
        <h4>Rule Preview</h4>
        <p class="muted" v-if="previewConditionText">If {{ previewConditionText }}, then {{ previewActionText }}.</p>
        <p class="muted" v-else>Define at least one valid condition and one action.</p>
      </section>

      <div class="inline" style="margin-top: 1rem">
        <button class="primary" :disabled="submitting">
          {{ submitting ? 'Saving...' : editingRuleId ? 'Update Rule' : 'Create Rule' }}
        </button>
      </div>
      <p v-if="error" class="error">{{ error }}</p>
      <p v-if="success" class="success">{{ success }}</p>
    </form>

    <article class="card" style="margin-top: 1rem">
      <div class="inline" style="justify-content: space-between">
        <h3>Existing Rules</h3>
        <button class="ghost" type="button" @click="loadRules">Refresh</button>
      </div>

      <p v-if="rules.length === 0" class="muted">No rules yet. Start with a template above.</p>

      <div v-else class="rules-list">
        <article v-for="rule in rules" :key="rule.id" class="rules-list-item">
          <div class="inline" style="justify-content: space-between">
            <div>
              <strong>{{ rule.name }}</strong>
              <p class="muted">Priority {{ rule.priority }} · {{ rule.is_active ? 'Active' : 'Paused' }}</p>
            </div>
            <span class="pill" :class="rule.is_active ? 'trace-status-success' : 'trace-status-skipped'">
              {{ rule.is_active ? 'ACTIVE' : 'PAUSED' }}
            </span>
          </div>

          <p class="muted rules-list-summary">{{ describeRule(rule) }}</p>

          <div class="inline rules-list-actions">
            <button class="ghost" type="button" @click="editRule(rule)">Edit</button>
            <button class="ghost" type="button" @click="toggleRule(rule)">
              {{ rule.is_active ? 'Pause' : 'Activate' }}
            </button>
            <button class="ghost" type="button" @click="removeRule(rule.id)">Delete</button>
          </div>
        </article>
      </div>
    </article>
  </section>
</template>

<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';

import { apiDelete, apiGet, apiPost, apiPut } from '@/services/api';

type RuleMatchMode = 'all' | 'any';
type FieldType = 'text' | 'number' | 'date' | 'list';

type Rule = {
  id: number;
  name: string;
  priority: number;
  is_active: boolean;
  conditions: Record<string, unknown>;
  actions: Record<string, unknown>;
};

type RuleListResponse = {
  items: Rule[];
};

type RuleTemplate = {
  id: string;
  title: string;
  description: string;
  defaults: {
    name: string;
    priority: number;
    matchMode: RuleMatchMode;
    conditions: Array<{ field: string; operator: string; value: string | string[] }>;
    actions: {
      category?: string;
      tags?: string[];
      tagMode?: 'append' | 'replace';
      notes?: string;
    };
  };
};

type ConditionBuilderRow = {
  id: string;
  field: string;
  operator: string;
  value: string;
};

type FieldOption = {
  key: string;
  label: string;
  type: FieldType;
  placeholder: string;
};

type OperatorOption = {
  key: string;
  label: string;
  allowedTypes: FieldType[];
};

const fieldOptions: FieldOption[] = [
  { key: 'merchant', label: 'Merchant', type: 'text', placeholder: 'uber, target, amazon' },
  { key: 'category', label: 'Category', type: 'text', placeholder: 'travel, meals' },
  { key: 'currency', label: 'Currency', type: 'text', placeholder: 'USD' },
  { key: 'payment_method', label: 'Payment Method', type: 'text', placeholder: 'card, cash' },
  { key: 'merchant_address', label: 'Merchant Address', type: 'text', placeholder: 'city, street' },
  { key: 'receipt_number', label: 'Receipt Number', type: 'text', placeholder: 'INV-1234' },
  { key: 'notes', label: 'Notes', type: 'text', placeholder: 'contains keyword' },
  { key: 'tags', label: 'Tags', type: 'list', placeholder: 'recurring' },
  { key: 'total', label: 'Total Amount', type: 'number', placeholder: '100.00' },
  { key: 'subtotal', label: 'Subtotal', type: 'number', placeholder: '90.00' },
  { key: 'tax', label: 'Tax', type: 'number', placeholder: '7.50' },
  { key: 'tip', label: 'Tip', type: 'number', placeholder: '12.00' },
  { key: 'ai_confidence', label: 'AI Confidence', type: 'number', placeholder: '0.75' },
  { key: 'purchased_at', label: 'Purchase Date', type: 'date', placeholder: 'YYYY-MM-DD' }
];

const operatorOptions: OperatorOption[] = [
  { key: 'contains', label: 'contains', allowedTypes: ['text', 'list'] },
  { key: 'not_contains', label: 'does not contain', allowedTypes: ['text', 'list'] },
  { key: 'equals', label: 'equals', allowedTypes: ['text', 'number', 'date', 'list'] },
  { key: 'not_equals', label: 'does not equal', allowedTypes: ['text', 'number', 'date', 'list'] },
  { key: 'starts_with', label: 'starts with', allowedTypes: ['text'] },
  { key: 'ends_with', label: 'ends with', allowedTypes: ['text'] },
  { key: 'in', label: 'is one of', allowedTypes: ['text', 'list'] },
  { key: 'gt', label: 'is greater than', allowedTypes: ['number', 'date'] },
  { key: 'gte', label: 'is at least', allowedTypes: ['number', 'date'] },
  { key: 'lt', label: 'is less than', allowedTypes: ['number', 'date'] },
  { key: 'lte', label: 'is at most', allowedTypes: ['number', 'date'] }
];

const categorySuggestions = ['Travel', 'Meals', 'Groceries', 'Office', 'Utilities', 'Software', 'Marketing', 'Lodging'];

const templates: RuleTemplate[] = [
  {
    id: 'rideshare',
    title: 'Rideshare To Travel',
    description: 'Auto-categorize Uber/Lyft transactions and tag them as rideshare.',
    defaults: {
      name: 'Auto categorize rideshare',
      priority: 40,
      matchMode: 'any',
      conditions: [
        { field: 'merchant', operator: 'contains', value: 'uber' },
        { field: 'merchant', operator: 'contains', value: 'lyft' }
      ],
      actions: {
        category: 'Travel',
        tags: ['rideshare'],
        tagMode: 'append'
      }
    }
  },
  {
    id: 'high-value',
    title: 'High-Value Alert',
    description: 'Flag expensive receipts so they are easy to review later.',
    defaults: {
      name: 'Flag high-value purchases',
      priority: 20,
      matchMode: 'all',
      conditions: [{ field: 'total', operator: 'gte', value: '250' }],
      actions: {
        tags: ['high-value', 'review'],
        tagMode: 'append',
        notes: 'High-value transaction. Confirm receipt details and approvals.'
      }
    }
  },
  {
    id: 'meals',
    title: 'Meals Normalizer',
    description: 'Tag and categorize food receipts to keep spend reports clean.',
    defaults: {
      name: 'Categorize meals',
      priority: 80,
      matchMode: 'any',
      conditions: [
        { field: 'merchant', operator: 'contains', value: 'cafe' },
        { field: 'merchant', operator: 'contains', value: 'restaurant' },
        { field: 'category', operator: 'contains', value: 'food' }
      ],
      actions: {
        category: 'Meals',
        tags: ['meal'],
        tagMode: 'append'
      }
    }
  },
  {
    id: 'subscriptions',
    title: 'Recurring Subscriptions',
    description: 'Identify recurring software/services for monthly review.',
    defaults: {
      name: 'Mark subscription spend',
      priority: 55,
      matchMode: 'any',
      conditions: [
        { field: 'merchant', operator: 'contains', value: 'openai' },
        { field: 'merchant', operator: 'contains', value: 'github' },
        { field: 'merchant', operator: 'contains', value: 'google' }
      ],
      actions: {
        category: 'Software',
        tags: ['subscription', 'recurring'],
        tagMode: 'append'
      }
    }
  }
];

const rules = ref<Rule[]>([]);
const formName = ref('');
const formPriority = ref(100);
const formActive = ref(true);
const matchMode = ref<RuleMatchMode>('all');
const conditions = ref<ConditionBuilderRow[]>([createConditionRow()]);

const actionCategory = ref('');
const actionTags = ref('');
const tagMode = ref<'append' | 'replace'>('append');
const actionNotes = ref('');

const editingRuleId = ref<number | null>(null);
const submitting = ref(false);
const error = ref('');
const success = ref('');

const activeRuleCount = computed(() => rules.value.filter((rule) => rule.is_active).length);

const previewConditionText = computed(() => {
  const validRows = conditions.value
    .filter((row) => row.value.trim() !== '')
    .map((row) => describeCondition(row.field, row.operator, row.value));

  if (validRows.length === 0) {
    return '';
  }

  return validRows.join(matchMode.value === 'all' ? ' and ' : ' or ');
});

const previewActionText = computed(() => {
  const parts: string[] = [];
  if (actionCategory.value.trim() !== '') {
    parts.push(`set category to "${actionCategory.value.trim()}"`);
  }

  const tags = parseCommaSeparated(actionTags.value);
  if (tags.length > 0) {
    parts.push(`${tagMode.value === 'replace' ? 'replace tags with' : 'add tags'} ${tags.map((tag) => `#${tag}`).join(', ')}`);
  }

  if (actionNotes.value.trim() !== '') {
    parts.push('set notes to a standard message');
  }

  return parts.length > 0 ? parts.join(', ') : 'no actions are defined yet';
});

onMounted(async () => {
  await loadRules();
});

async function loadRules(): Promise<void> {
  error.value = '';
  const response = await apiGet<RuleListResponse>('/rules');
  rules.value = response.items;
}

function createConditionRow(field = 'merchant'): ConditionBuilderRow {
  const option = getFieldOption(field);
  const defaultOperator = defaultOperatorForType(option?.type ?? 'text');
  return {
    id: crypto.randomUUID(),
    field,
    operator: defaultOperator,
    value: ''
  };
}

function getFieldOption(field: string): FieldOption | undefined {
  return fieldOptions.find((option) => option.key === field);
}

function defaultOperatorForType(type: FieldType): string {
  if (type === 'number' || type === 'date') {
    return 'gte';
  }
  return 'contains';
}

function operatorsForField(field: string): OperatorOption[] {
  const type = getFieldOption(field)?.type ?? 'text';
  return operatorOptions.filter((operator) => operator.allowedTypes.includes(type));
}

function inputTypeForField(field: string): 'text' | 'number' | 'date' {
  const type = getFieldOption(field)?.type ?? 'text';
  if (type === 'number') {
    return 'number';
  }
  if (type === 'date') {
    return 'date';
  }
  return 'text';
}

function valuePlaceholder(field: string, operator: string): string {
  if (operator === 'in') {
    return 'Comma separated values';
  }

  return getFieldOption(field)?.placeholder ?? 'Value';
}

function onConditionFieldChange(condition: ConditionBuilderRow): void {
  const operators = operatorsForField(condition.field);
  if (!operators.some((operator) => operator.key === condition.operator)) {
    condition.operator = operators[0]?.key ?? 'contains';
    condition.value = '';
  }
}

function addCondition(): void {
  conditions.value.push(createConditionRow());
}

function removeCondition(index: number): void {
  if (conditions.value.length <= 1) {
    return;
  }
  conditions.value.splice(index, 1);
}

function parseCommaSeparated(value: string): string[] {
  return value
    .split(',')
    .map((item) => item.trim())
    .filter((item) => item !== '');
}

function normalizeConditionValue(field: string, operator: string, rawValue: string): string | number | string[] {
  const value = rawValue.trim();
  const type = getFieldOption(field)?.type ?? 'text';

  if (operator === 'in') {
    const values = parseCommaSeparated(value);
    if (values.length === 0) {
      throw new Error('Use comma-separated values for "is one of" conditions.');
    }
    return values;
  }

  if (type === 'number') {
    const parsed = Number(value);
    if (!Number.isFinite(parsed)) {
      throw new Error(`"${getFieldOption(field)?.label ?? field}" must be a valid number.`);
    }
    return parsed;
  }

  if (type === 'date') {
    if (!/^\d{4}-\d{2}-\d{2}$/.test(value)) {
      throw new Error(`"${getFieldOption(field)?.label ?? field}" must be a valid date (YYYY-MM-DD).`);
    }
    return value;
  }

  return value;
}

function buildConditionsPayload(): Record<string, unknown> {
  const rows = conditions.value.filter((row) => row.value.trim() !== '');
  if (rows.length === 0) {
    throw new Error('Add at least one condition with a value.');
  }

  const mapped = rows.map((row) => ({
    field: row.field,
    operator: row.operator,
    value: normalizeConditionValue(row.field, row.operator, row.value)
  }));

  return matchMode.value === 'all' ? { all: mapped } : { any: mapped };
}

function buildActionsPayload(): Record<string, unknown> {
  const setActions: Record<string, unknown> = {};
  const actions: Record<string, unknown> = {};

  if (actionCategory.value.trim() !== '') {
    setActions.category = actionCategory.value.trim();
  }

  if (actionNotes.value.trim() !== '') {
    setActions.notes = actionNotes.value.trim();
  }

  const tags = parseCommaSeparated(actionTags.value);
  if (tags.length > 0) {
    if (tagMode.value === 'replace') {
      setActions.tags = tags;
    } else {
      actions.append_tags = tags;
    }
  }

  if (Object.keys(setActions).length > 0) {
    actions.set = setActions;
  }

  if (Object.keys(actions).length === 0) {
    throw new Error('Add at least one action (category, tags, or notes).');
  }

  return actions;
}

async function saveRule(): Promise<void> {
  submitting.value = true;
  error.value = '';
  success.value = '';

  try {
    const payload = {
      name: formName.value.trim(),
      priority: formPriority.value,
      is_active: formActive.value,
      conditions: buildConditionsPayload(),
      actions: buildActionsPayload()
    };

    if (payload.name === '') {
      throw new Error('Rule name is required.');
    }

    if (editingRuleId.value !== null) {
      await apiPut(`/rules/${editingRuleId.value}`, payload);
      success.value = 'Rule updated.';
    } else {
      await apiPost('/rules', payload);
      success.value = 'Rule created.';
    }

    await loadRules();
    resetBuilder();
  } catch (err) {
    error.value = err instanceof Error ? err.message : 'Unable to save rule';
  } finally {
    submitting.value = false;
  }
}

function resetBuilder(): void {
  editingRuleId.value = null;
  formName.value = '';
  formPriority.value = 100;
  formActive.value = true;
  matchMode.value = 'all';
  conditions.value = [createConditionRow()];
  actionCategory.value = '';
  actionTags.value = '';
  tagMode.value = 'append';
  actionNotes.value = '';
}

function applyTemplate(template: RuleTemplate): void {
  editingRuleId.value = null;
  formName.value = template.defaults.name;
  formPriority.value = template.defaults.priority;
  formActive.value = true;
  matchMode.value = template.defaults.matchMode;
  conditions.value = template.defaults.conditions.map((condition) => ({
    id: crypto.randomUUID(),
    field: condition.field,
    operator: condition.operator,
    value: Array.isArray(condition.value) ? condition.value.join(', ') : String(condition.value)
  }));

  actionCategory.value = template.defaults.actions.category ?? '';
  actionTags.value = (template.defaults.actions.tags ?? []).join(', ');
  tagMode.value = template.defaults.actions.tagMode ?? 'append';
  actionNotes.value = template.defaults.actions.notes ?? '';

  success.value = `Template loaded: ${template.title}`;
  error.value = '';
}

function parseConditionsForBuilder(raw: unknown): { mode: RuleMatchMode; rows: ConditionBuilderRow[] } {
  const fallback = { mode: 'all' as RuleMatchMode, rows: [createConditionRow()] };

  if (!raw || typeof raw !== 'object') {
    return fallback;
  }

  const input = raw as Record<string, unknown>;
  const allConditions = Array.isArray(input.all) ? input.all : null;
  const anyConditions = Array.isArray(input.any) ? input.any : null;

  const source = allConditions ?? anyConditions;
  const mode: RuleMatchMode = allConditions ? 'all' : anyConditions ? 'any' : 'all';

  if (!source) {
    if (typeof input.field === 'string') {
      return {
        mode: 'all',
        rows: [
          {
            id: crypto.randomUUID(),
            field: input.field,
            operator: typeof input.operator === 'string' ? input.operator : 'contains',
            value: conditionValueToString(input.value)
          }
        ]
      };
    }

    return fallback;
  }

  const rows = source
    .filter((condition): condition is Record<string, unknown> => typeof condition === 'object' && condition !== null)
    .map((condition) => {
      const field = typeof condition.field === 'string' ? condition.field : 'merchant';
      const operator = typeof condition.operator === 'string' ? condition.operator : defaultOperatorForType(getFieldOption(field)?.type ?? 'text');
      const validOperators = operatorsForField(field).map((item) => item.key);

      return {
        id: crypto.randomUUID(),
        field,
        operator: validOperators.includes(operator) ? operator : defaultOperatorForType(getFieldOption(field)?.type ?? 'text'),
        value: conditionValueToString(condition.value)
      };
    });

  return {
    mode,
    rows: rows.length > 0 ? rows : [createConditionRow()]
  };
}

function conditionValueToString(value: unknown): string {
  if (Array.isArray(value)) {
    return value.map((item) => String(item)).join(', ');
  }

  if (value === null || value === undefined) {
    return '';
  }

  return String(value);
}

function editRule(rule: Rule): void {
  editingRuleId.value = rule.id;
  formName.value = rule.name;
  formPriority.value = rule.priority;
  formActive.value = rule.is_active;

  const parsedConditions = parseConditionsForBuilder(rule.conditions);
  matchMode.value = parsedConditions.mode;
  conditions.value = parsedConditions.rows;

  const actions = (rule.actions && typeof rule.actions === 'object') ? (rule.actions as Record<string, unknown>) : {};
  const set = (actions.set && typeof actions.set === 'object') ? (actions.set as Record<string, unknown>) : {};

  actionCategory.value = typeof set.category === 'string' ? set.category : '';
  actionNotes.value = typeof set.notes === 'string' ? set.notes : '';

  const replaceTags = Array.isArray(set.tags) ? set.tags.map((tag) => String(tag).trim()).filter((tag) => tag !== '') : [];
  const appendTags = Array.isArray(actions.append_tags)
    ? actions.append_tags.map((tag) => String(tag).trim()).filter((tag) => tag !== '')
    : [];

  if (replaceTags.length > 0) {
    tagMode.value = 'replace';
    actionTags.value = replaceTags.join(', ');
  } else {
    tagMode.value = 'append';
    actionTags.value = appendTags.join(', ');
  }

  success.value = '';
  error.value = '';
}

async function toggleRule(rule: Rule): Promise<void> {
  error.value = '';

  try {
    await apiPut(`/rules/${rule.id}`, { is_active: !rule.is_active });
    await loadRules();
  } catch (err) {
    error.value = err instanceof Error ? err.message : 'Unable to update rule status';
  }
}

async function removeRule(id: number): Promise<void> {
  error.value = '';
  success.value = '';

  try {
    await apiDelete(`/rules/${id}`);
    await loadRules();
    if (editingRuleId.value === id) {
      resetBuilder();
    }
    success.value = 'Rule deleted.';
  } catch (err) {
    error.value = err instanceof Error ? err.message : 'Unable to delete rule';
  }
}

function describeCondition(field: string, operator: string, rawValue: string): string {
  const fieldLabel = getFieldOption(field)?.label ?? field;
  const operatorLabel = operatorOptions.find((item) => item.key === operator)?.label ?? operator;

  if (operator === 'in') {
    const values = parseCommaSeparated(rawValue);
    return `${fieldLabel} ${operatorLabel} [${values.join(', ')}]`;
  }

  return `${fieldLabel} ${operatorLabel} "${rawValue.trim()}"`;
}

function describeRule(rule: Rule): string {
  const parsedConditions = parseConditionsForBuilder(rule.conditions);
  const conditionText = parsedConditions.rows
    .filter((row) => row.value.trim() !== '')
    .map((row) => describeCondition(row.field, row.operator, row.value))
    .join(parsedConditions.mode === 'all' ? ' and ' : ' or ');

  const actions = (rule.actions && typeof rule.actions === 'object') ? (rule.actions as Record<string, unknown>) : {};
  const set = (actions.set && typeof actions.set === 'object') ? (actions.set as Record<string, unknown>) : {};

  const actionParts: string[] = [];
  if (typeof set.category === 'string' && set.category.trim() !== '') {
    actionParts.push(`set category to ${set.category.trim()}`);
  }

  if (Array.isArray(set.tags) && set.tags.length > 0) {
    actionParts.push(`replace tags with ${(set.tags as unknown[]).map((tag) => `#${String(tag)}`).join(', ')}`);
  }

  if (Array.isArray(actions.append_tags) && actions.append_tags.length > 0) {
    actionParts.push(`add tags ${(actions.append_tags as unknown[]).map((tag) => `#${String(tag)}`).join(', ')}`);
  }

  if (typeof set.notes === 'string' && set.notes.trim() !== '') {
    actionParts.push('set notes');
  }

  if (conditionText === '' && actionParts.length === 0) {
    return 'Custom rule';
  }

  if (conditionText === '') {
    return `Then ${actionParts.join(', ')}.`;
  }

  return `If ${conditionText}, then ${actionParts.join(', ') || 'no action'}.`;
}
</script>
