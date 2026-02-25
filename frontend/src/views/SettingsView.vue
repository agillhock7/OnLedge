<template>
  <section class="page settings-control-page">
    <header class="settings-control-head card">
      <p class="kicker">Workspace Controls</p>
      <h1>Settings</h1>
      <p class="muted">Manage your account profile, support workflow, and admin operations from one focused control panel.</p>
    </header>

    <section class="settings-overview-grid" style="margin-top: 1rem">
      <article class="card settings-account-card">
        <div class="inline" style="justify-content: space-between">
          <h3>Account Profile</h3>
          <span class="pill">{{ auth.user?.role || 'user' }}</span>
        </div>

        <div class="settings-account-grid">
          <div>
            <span>Email</span>
            <strong>{{ auth.user?.email || '-' }}</strong>
          </div>
          <div>
            <span>Status</span>
            <strong>{{ auth.user?.is_active ? 'Active' : 'Disabled' }}</strong>
          </div>
          <div>
            <span>Seed Account</span>
            <strong>{{ auth.user?.is_seed ? 'Yes' : 'No' }}</strong>
          </div>
          <div>
            <span>Permissions</span>
            <strong>{{ auth.isOwner ? 'Owner access' : auth.isAdmin ? 'Admin access' : 'Standard user' }}</strong>
          </div>
        </div>

        <p class="muted" style="margin: 0">
          Account and authentication security settings are managed server-side for this deployment profile.
        </p>
      </article>

      <article class="card settings-preference-card">
        <h3>Workspace Preferences</h3>
        <p class="muted">These preferences are saved locally on this device.</p>

        <div class="settings-preference-grid">
          <label>
            Default Currency
            <select v-model="preferences.defaultCurrency">
              <option v-for="currency in currencyOptions" :key="currency" :value="currency">{{ currency }}</option>
            </select>
          </label>

          <label class="settings-toggle-row">
            <input type="checkbox" v-model="preferences.autoProcessAfterCapture" />
            <span>Auto-run AI after capture</span>
          </label>

          <label class="settings-toggle-row">
            <input type="checkbox" v-model="preferences.compactDensity" />
            <span>Prefer compact table density</span>
          </label>

          <label class="settings-toggle-row">
            <input type="checkbox" v-model="preferences.showHints" />
            <span>Show onboarding hints in advanced views</span>
          </label>
        </div>

        <div class="inline">
          <button class="primary" type="button" @click="savePreferences">Save Preferences</button>
        </div>
        <p v-if="preferenceNotice" class="success" style="margin: 0">{{ preferenceNotice }}</p>
      </article>

      <article class="card settings-preference-card">
        <h3>Email Notifications</h3>
        <p class="muted">Manage automated report delivery and account notification preferences.</p>

        <div class="settings-preference-grid">
          <label class="settings-toggle-row">
            <input type="checkbox" v-model="emailPrefs.weekly_report_enabled" />
            <span>Weekly spending report via email</span>
          </label>
        </div>

        <p class="muted" style="margin: 0">
          Weekly reports are enabled by default for new accounts.
          <span v-if="emailPrefs.weekly_report_last_sent_at">
            Last sent: {{ formatDate(emailPrefs.weekly_report_last_sent_at) }}.
          </span>
        </p>

        <div class="inline">
          <button class="primary" type="button" :disabled="emailPrefsSaving" @click="saveEmailPreferences">
            {{ emailPrefsSaving ? 'Saving...' : 'Save Email Preferences' }}
          </button>
        </div>
        <p v-if="emailPrefsNotice" class="success" style="margin: 0">{{ emailPrefsNotice }}</p>
        <p v-if="emailPrefsError" class="error" style="margin: 0">{{ emailPrefsError }}</p>
      </article>
    </section>

    <article class="card settings-support-card" style="margin-top: 1rem">
      <div class="inline" style="justify-content: space-between; align-items: flex-start">
        <div>
          <h3>Support Workspace</h3>
          <p class="muted">Threaded conversations between users and admins, with assignment and lifecycle tracking.</p>
        </div>

        <div v-if="auth.isAdmin" class="settings-support-scope">
          <button class="ghost" type="button" :class="{ active: supportScope === 'mine' }" @click="switchSupportScope('mine')">My Tickets</button>
          <button class="ghost" type="button" :class="{ active: supportScope === 'admin' }" @click="switchSupportScope('admin')">Admin Queue</button>
        </div>
      </div>

      <form class="settings-form" @submit.prevent="createTicket">
        <div>
          <label for="ticket-subject">Subject</label>
          <input id="ticket-subject" v-model="ticketForm.subject" maxlength="180" required />
        </div>

        <div>
          <label for="ticket-priority">Priority</label>
          <select id="ticket-priority" v-model="ticketForm.priority">
            <option value="low">Low</option>
            <option value="normal">Normal</option>
            <option value="high">High</option>
            <option value="urgent">Urgent</option>
          </select>
        </div>

        <div class="settings-field-wide">
          <label for="ticket-message">Message</label>
          <textarea id="ticket-message" v-model="ticketForm.message" minlength="10" required></textarea>
        </div>

        <div class="settings-field-wide inline">
          <button class="primary" :disabled="ticketSubmitting">{{ ticketSubmitting ? 'Submitting...' : 'Create Ticket' }}</button>
        </div>
      </form>

      <div v-if="auth.isAdmin && supportScope === 'admin'" class="settings-support-filters" style="margin-top: 0.8rem">
        <label>
          Status
          <select v-model="adminTicketFilters.status" @change="loadAdminTickets">
            <option value="">All</option>
            <option value="open">Open</option>
            <option value="in_progress">In Progress</option>
            <option value="resolved">Resolved</option>
            <option value="closed">Closed</option>
          </select>
        </label>

        <label>
          Priority
          <select v-model="adminTicketFilters.priority" @change="loadAdminTickets">
            <option value="">All</option>
            <option value="low">Low</option>
            <option value="normal">Normal</option>
            <option value="high">High</option>
            <option value="urgent">Urgent</option>
          </select>
        </label>

        <label>
          Assignment
          <select v-model="adminTicketFilters.assignment" @change="loadAdminTickets">
            <option value="all">All</option>
            <option value="mine">Assigned To Me</option>
            <option value="unassigned">Unassigned</option>
          </select>
        </label>

        <label>
          Search
          <input
            v-model="adminTicketFilters.q"
            placeholder="subject, message, reporter"
            @keydown.enter.prevent="loadAdminTickets"
          />
        </label>

        <div class="inline" style="align-items: end">
          <button class="ghost" type="button" @click="loadAdminTickets">Refresh Queue</button>
        </div>
      </div>

      <p v-if="ticketNotice" class="success">{{ ticketNotice }}</p>
      <p v-if="ticketError" class="error">{{ ticketError }}</p>

      <div class="settings-support-layout" style="margin-top: 1rem">
        <section class="settings-support-column">
          <div class="inline" style="justify-content: space-between">
            <h4 style="margin: 0">{{ supportScope === 'admin' ? 'Admin Queue' : 'My Tickets' }}</h4>
            <span class="pill">{{ supportTickets.length }} total</span>
          </div>

          <div class="settings-list" v-if="supportTickets.length">
            <article
              class="settings-list-item settings-ticket-item"
              :class="{ active: activeTicket?.id === ticket.id }"
              v-for="ticket in supportTickets"
              :key="ticket.id"
              @click="openTicket(ticket.id)"
            >
              <div class="inline" style="justify-content: space-between; width: 100%">
                <strong>#{{ ticket.id }} · {{ ticket.subject }}</strong>
                <span class="pill">{{ ticket.status }}</span>
              </div>

              <p class="muted settings-item-meta">
                Priority: {{ ticket.priority }}
                <span v-if="ticket.assigned_admin_email"> · Assigned: {{ ticket.assigned_admin_email }}</span>
                <span v-if="ticket.reporter_email"> · Reporter: {{ ticket.reporter_email }}</span>
              </p>

              <p class="settings-ticket-preview">{{ ticket.last_message_preview || ticket.message || 'No message preview yet.' }}</p>

              <p class="muted settings-item-meta" style="margin: 0.2rem 0 0">
                {{ ticket.message_count || 0 }} messages
                <span v-if="ticket.last_message_at"> · Last update {{ formatDate(ticket.last_message_at) }}</span>
              </p>
            </article>
          </div>
          <p v-else class="muted">No tickets yet.</p>
        </section>

        <section class="settings-thread-column">
          <article class="card settings-thread-card" v-if="activeTicket">
            <div class="inline" style="justify-content: space-between">
              <div>
                <h4 style="margin: 0">#{{ activeTicket.id }} · {{ activeTicket.subject }}</h4>
                <p class="muted settings-item-meta" style="margin: 0.35rem 0 0">
                  Created {{ formatDate(activeTicket.created_at) }}
                  <span v-if="activeTicket.reporter_email"> · Reporter: {{ activeTicket.reporter_email }}</span>
                </p>
              </div>
              <span class="pill">{{ activeTicket.status }}</span>
            </div>

            <div v-if="auth.isAdmin" class="settings-form" style="margin-top: 0.9rem">
              <div>
                <label>Status</label>
                <select v-model="activeTicket.status">
                  <option value="open">Open</option>
                  <option value="in_progress">In Progress</option>
                  <option value="resolved">Resolved</option>
                  <option value="closed">Closed</option>
                </select>
              </div>

              <div>
                <label>Priority</label>
                <select v-model="activeTicket.priority">
                  <option value="low">Low</option>
                  <option value="normal">Normal</option>
                  <option value="high">High</option>
                  <option value="urgent">Urgent</option>
                </select>
              </div>

              <div>
                <label>Assigned Admin</label>
                <select v-model="activeTicket.assigned_admin_id">
                  <option :value="null">Unassigned</option>
                  <option v-for="assignee in supportAssignees" :key="assignee.id" :value="assignee.id">{{ assignee.email }}</option>
                </select>
              </div>

              <div class="settings-field-wide">
                <label>Admin Note</label>
                <textarea v-model="activeTicket.admin_note"></textarea>
              </div>

              <div class="settings-field-wide inline">
                <button class="secondary" type="button" :disabled="ticketSaving" @click="saveActiveTicket">
                  {{ ticketSaving ? 'Saving...' : 'Save Ticket Updates' }}
                </button>
              </div>
            </div>

            <div class="settings-thread-list" style="margin-top: 0.95rem">
              <div v-if="threadLoading" class="muted">Loading conversation...</div>

              <article v-else class="settings-thread-message" :class="messageClasses(message)" v-for="message in activeMessages" :key="message.id">
                <div class="inline" style="justify-content: space-between; width: 100%">
                  <strong>
                    {{ messageAuthorLabel(message) }}
                    <span v-if="message.is_internal" class="pill" style="margin-left: 0.4rem">Internal</span>
                  </strong>
                  <span class="muted settings-item-meta">{{ formatDate(message.created_at) }}</span>
                </div>
                <p class="settings-thread-body">{{ message.body }}</p>
              </article>
            </div>

            <form class="settings-thread-reply" @submit.prevent="sendReply" style="margin-top: 0.9rem">
              <label for="thread-reply">Reply</label>
              <textarea id="thread-reply" v-model="replyForm.message" minlength="2" maxlength="6000" required></textarea>

              <label v-if="auth.isAdmin" class="settings-toggle-row" style="margin-top: 0.4rem">
                <input type="checkbox" v-model="replyForm.is_internal" />
                <span>Internal note (hidden from user)</span>
              </label>

              <div class="inline" style="margin-top: 0.55rem">
                <button class="primary" :disabled="replySubmitting">
                  {{ replySubmitting ? 'Sending...' : 'Send Reply' }}
                </button>
              </div>
            </form>
          </article>

          <article v-else class="card settings-thread-card settings-thread-empty">
            <h4>Select a ticket</h4>
            <p class="muted" style="margin: 0">Choose a ticket from the list to view and continue the thread.</p>
          </article>
        </section>
      </div>
    </article>

    <section v-if="auth.isAdmin" class="settings-admin-stack" style="margin-top: 1rem">
      <article class="card">
        <h3>Admin: User Access</h3>
        <p class="muted">Create role-based accounts and manage activation state.</p>

        <form class="settings-form" @submit.prevent="createUser">
          <div>
            <label for="admin-email">Email</label>
            <input id="admin-email" type="email" v-model="newUser.email" required autocomplete="off" />
          </div>

          <div>
            <label for="admin-password">Temporary Password</label>
            <input id="admin-password" type="password" v-model="newUser.password" minlength="10" required autocomplete="new-password" />
          </div>

          <div>
            <label for="admin-role">Role</label>
            <select id="admin-role" v-model="newUser.role">
              <option value="user">User</option>
              <option value="admin">Admin</option>
              <option v-if="auth.isOwner" value="owner">Owner</option>
            </select>
          </div>

          <div class="settings-inline-toggle">
            <input id="admin-seed" type="checkbox" v-model="newUser.is_seed" :disabled="!auth.isOwner" style="width: auto" />
            <label for="admin-seed" style="margin: 0">Mark as seed user</label>
          </div>

          <div class="settings-field-wide inline">
            <button class="primary" :disabled="userSubmitting">{{ userSubmitting ? 'Creating...' : 'Create User' }}</button>
          </div>
        </form>

        <p v-if="userError" class="error">{{ userError }}</p>

        <div class="settings-list" v-if="users.length">
          <article class="settings-list-item" v-for="user in users" :key="user.id">
            <div class="inline" style="justify-content: space-between; width: 100%">
              <strong>{{ user.email }}</strong>
              <span class="pill">{{ user.role }}</span>
            </div>

            <div class="settings-form" style="margin-top: 0.8rem">
              <div>
                <label>Role</label>
                <select v-model="user.role" :disabled="!canEditRole(user)">
                  <option value="user">User</option>
                  <option value="admin">Admin</option>
                  <option value="owner">Owner</option>
                </select>
              </div>

              <div>
                <label>Status</label>
                <select v-model="user.is_active" :disabled="!canEditStatus(user)">
                  <option :value="true">Active</option>
                  <option :value="false">Disabled</option>
                </select>
              </div>

              <div class="settings-inline-toggle">
                <input
                  :id="`seed-${user.id}`"
                  type="checkbox"
                  v-model="user.is_seed"
                  :disabled="!auth.isOwner"
                  style="width: auto"
                />
                <label :for="`seed-${user.id}`" style="margin: 0">Seed</label>
              </div>

              <div class="inline" style="align-items: end">
                <button class="secondary" @click.prevent="saveUser(user)">Save</button>
              </div>
            </div>
          </article>
        </div>
      </article>
    </section>
  </section>
</template>

<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue';

import { apiGet, apiPost, apiPut } from '@/services/api';
import { useAuthStore } from '@/stores/auth';

type Role = 'user' | 'admin' | 'owner';
type TicketStatus = 'open' | 'in_progress' | 'resolved' | 'closed';
type TicketPriority = 'low' | 'normal' | 'high' | 'urgent';

type ManagedUser = {
  id: number;
  email: string;
  role: Role;
  is_active: boolean;
  is_seed: boolean;
  disabled_at?: string | null;
  created_at: string;
  updated_at?: string | null;
};

type SupportTicket = {
  id: number;
  user_id: number;
  subject: string;
  message: string;
  status: TicketStatus;
  priority: TicketPriority;
  assigned_admin_id?: number | null;
  assigned_admin_email?: string | null;
  reporter_email?: string | null;
  admin_note?: string | null;
  message_count?: number;
  last_message_at?: string | null;
  last_message_preview?: string | null;
  last_message_by_user_id?: number | null;
  last_message_author_email?: string | null;
  last_message_author_role?: Role | null;
  created_at: string;
  updated_at: string;
};

type SupportTicketMessage = {
  id: number;
  ticket_id: number;
  author_user_id: number;
  author_email?: string | null;
  author_role?: Role;
  body: string;
  is_internal: boolean;
  created_at: string;
  updated_at: string;
};

type EmailPreferences = {
  user_id: number;
  weekly_report_enabled: boolean;
  weekly_report_last_sent_at: string;
  welcome_email_sent_at: string;
  created_at: string;
  updated_at: string;
};

const PREFERENCE_KEY = 'onledge.settings.preferences.v1';

const auth = useAuthStore();

const ticketSubmitting = ref(false);
const ticketSaving = ref(false);
const ticketError = ref('');
const ticketNotice = ref('');
const ticketLoading = ref(false);
const threadLoading = ref(false);
const replySubmitting = ref(false);

const myTickets = ref<SupportTicket[]>([]);
const adminTickets = ref<SupportTicket[]>([]);
const activeTicket = ref<SupportTicket | null>(null);
const activeMessages = ref<SupportTicketMessage[]>([]);

const users = ref<ManagedUser[]>([]);
const userSubmitting = ref(false);
const userError = ref('');
const preferenceNotice = ref('');
const emailPrefsNotice = ref('');
const emailPrefsError = ref('');
const emailPrefsSaving = ref(false);

const supportScope = ref<'mine' | 'admin'>('mine');
const adminTicketFilters = reactive({
  status: '',
  priority: '',
  assignment: 'all',
  q: ''
});

const replyForm = reactive({
  message: '',
  is_internal: false
});

const currencyOptions = ['USD', 'EUR', 'GBP', 'CAD', 'AUD', 'JPY'];

const preferences = reactive({
  defaultCurrency: 'USD',
  autoProcessAfterCapture: false,
  compactDensity: false,
  showHints: true
});

const emailPrefs = reactive<EmailPreferences>({
  user_id: 0,
  weekly_report_enabled: true,
  weekly_report_last_sent_at: '',
  welcome_email_sent_at: '',
  created_at: '',
  updated_at: ''
});

const ticketForm = reactive({
  subject: '',
  message: '',
  priority: 'normal' as TicketPriority
});

const newUser = reactive({
  email: '',
  password: '',
  role: 'admin' as Role,
  is_seed: false
});

const isAdmin = computed(() => auth.isAdmin);

const supportTickets = computed(() => (supportScope.value === 'admin' && auth.isAdmin ? adminTickets.value : myTickets.value));

const supportAssignees = computed(() =>
  users.value
    .filter((user) => user.is_active && (user.role === 'admin' || user.role === 'owner'))
    .sort((a, b) => a.email.localeCompare(b.email))
);

function formatDate(value: string | null | undefined): string {
  if (!value) {
    return '-';
  }

  try {
    return new Date(value).toLocaleString();
  } catch {
    return value;
  }
}

function switchSupportScope(scope: 'mine' | 'admin'): void {
  supportScope.value = scope;
  if (scope === 'admin' && auth.isAdmin) {
    void loadAdminTickets();
  }
}

function messageAuthorLabel(message: SupportTicketMessage): string {
  const role = message.author_role || 'user';
  const base = message.author_email || `User #${message.author_user_id}`;
  if (auth.user && message.author_user_id === auth.user.id) {
    return `You (${role})`;
  }
  return `${base} (${role})`;
}

function messageClasses(message: SupportTicketMessage): string {
  const own = auth.user ? message.author_user_id === auth.user.id : false;
  if (message.is_internal) {
    return own ? 'is-own is-internal' : 'is-internal';
  }
  return own ? 'is-own' : '';
}

function syncActiveTicketSummary(): void {
  if (!activeTicket.value) {
    return;
  }

  const ticketId = activeTicket.value.id;
  const fromMine = myTickets.value.find((ticket) => ticket.id === ticketId);
  const fromAdmin = adminTickets.value.find((ticket) => ticket.id === ticketId);
  const latest = fromAdmin || fromMine;
  if (!latest) {
    return;
  }

  activeTicket.value = {
    ...activeTicket.value,
    ...latest
  };
}

function canEditRole(user: ManagedUser): boolean {
  if (!auth.user) {
    return false;
  }
  if (user.role === 'owner' && !auth.isOwner) {
    return false;
  }
  return true;
}

function canEditStatus(user: ManagedUser): boolean {
  if (!auth.user) {
    return false;
  }
  if (user.id === auth.user.id) {
    return false;
  }
  if (user.role === 'owner' && !auth.isOwner) {
    return false;
  }
  return true;
}

function loadPreferences(): void {
  if (typeof localStorage === 'undefined') {
    return;
  }

  const raw = localStorage.getItem(PREFERENCE_KEY);
  if (!raw) {
    return;
  }

  try {
    const parsed = JSON.parse(raw) as Partial<typeof preferences>;

    if (typeof parsed.defaultCurrency === 'string' && currencyOptions.includes(parsed.defaultCurrency)) {
      preferences.defaultCurrency = parsed.defaultCurrency;
    }

    if (typeof parsed.autoProcessAfterCapture === 'boolean') {
      preferences.autoProcessAfterCapture = parsed.autoProcessAfterCapture;
    }

    if (typeof parsed.compactDensity === 'boolean') {
      preferences.compactDensity = parsed.compactDensity;
    }

    if (typeof parsed.showHints === 'boolean') {
      preferences.showHints = parsed.showHints;
    }
  } catch {
    // ignore malformed local preference payload
  }
}

function savePreferences(): void {
  if (typeof localStorage === 'undefined') {
    preferenceNotice.value = 'Local storage unavailable in this browser.';
    return;
  }

  localStorage.setItem(PREFERENCE_KEY, JSON.stringify(preferences));
  preferenceNotice.value = 'Preferences saved for this device.';
}

async function loadEmailPreferences() {
  try {
    const response = await apiGet<{ item: EmailPreferences }>('/notifications/preferences');
    Object.assign(emailPrefs, response.item);
  } catch (error) {
    emailPrefsError.value = error instanceof Error ? error.message : 'Unable to load email notification preferences';
  }
}

async function saveEmailPreferences() {
  emailPrefsSaving.value = true;
  emailPrefsError.value = '';
  emailPrefsNotice.value = '';

  try {
    const response = await apiPut<{ item: EmailPreferences }>('/notifications/preferences', {
      weekly_report_enabled: emailPrefs.weekly_report_enabled
    });
    Object.assign(emailPrefs, response.item);
    emailPrefsNotice.value = 'Email notification preferences saved.';
  } catch (error) {
    emailPrefsError.value = error instanceof Error ? error.message : 'Unable to update email preferences';
  } finally {
    emailPrefsSaving.value = false;
  }
}

async function loadMyTickets() {
  ticketLoading.value = true;
  try {
    const response = await apiGet<{ items: SupportTicket[] }>('/support/tickets/my');
    myTickets.value = response.items;
    syncActiveTicketSummary();
  } finally {
    ticketLoading.value = false;
  }
}

async function loadAdminTickets() {
  if (!isAdmin.value) {
    return;
  }

  ticketLoading.value = true;
  try {
    const params = new URLSearchParams();
    if (adminTicketFilters.status !== '') {
      params.set('status', adminTicketFilters.status);
    }
    if (adminTicketFilters.priority !== '') {
      params.set('priority', adminTicketFilters.priority);
    }
    if (adminTicketFilters.assignment !== '') {
      params.set('assignment', adminTicketFilters.assignment);
    }
    if (adminTicketFilters.q.trim() !== '') {
      params.set('q', adminTicketFilters.q.trim());
    }

    const suffix = params.toString() !== '' ? `?${params.toString()}` : '';
    const response = await apiGet<{ items: SupportTicket[] }>(`/admin/tickets${suffix}`);
    adminTickets.value = response.items;
    syncActiveTicketSummary();
  } finally {
    ticketLoading.value = false;
  }
}

async function openTicket(ticketId: number) {
  threadLoading.value = true;
  ticketError.value = '';

  try {
    const response = await apiGet<{ item: SupportTicket; messages: SupportTicketMessage[] }>(`/support/tickets/${ticketId}`);
    activeTicket.value = response.item;
    activeMessages.value = response.messages;
    replyForm.message = '';
    replyForm.is_internal = false;
  } catch (error) {
    ticketError.value = error instanceof Error ? error.message : 'Unable to open ticket thread';
  } finally {
    threadLoading.value = false;
  }
}

async function createTicket() {
  ticketSubmitting.value = true;
  ticketError.value = '';
  ticketNotice.value = '';

  try {
    const response = await apiPost<{ item: SupportTicket; messages: SupportTicketMessage[] }>('/support/tickets', {
      subject: ticketForm.subject,
      message: ticketForm.message,
      priority: ticketForm.priority
    });

    ticketForm.subject = '';
    ticketForm.message = '';
    ticketForm.priority = 'normal';

    ticketNotice.value = 'Support ticket created.';

    await loadMyTickets();
    if (isAdmin.value && supportScope.value === 'admin') {
      await loadAdminTickets();
    }

    if (response.item) {
      activeTicket.value = response.item;
      activeMessages.value = response.messages || [];
      supportScope.value = 'mine';
    }
  } catch (error) {
    ticketError.value = error instanceof Error ? error.message : 'Unable to create ticket';
  } finally {
    ticketSubmitting.value = false;
  }
}

async function sendReply() {
  if (!activeTicket.value) {
    return;
  }

  replySubmitting.value = true;
  ticketError.value = '';
  ticketNotice.value = '';

  try {
    const response = await apiPost<{ item: SupportTicket; messages: SupportTicketMessage[] }>(
      `/support/tickets/${activeTicket.value.id}/messages`,
      {
        message: replyForm.message,
        is_internal: auth.isAdmin ? replyForm.is_internal : false
      }
    );

    activeTicket.value = response.item;
    activeMessages.value = response.messages;

    replyForm.message = '';
    replyForm.is_internal = false;

    await loadMyTickets();
    if (isAdmin.value) {
      await loadAdminTickets();
    }

    ticketNotice.value = 'Reply sent.';
  } catch (error) {
    ticketError.value = error instanceof Error ? error.message : 'Unable to send reply';
  } finally {
    replySubmitting.value = false;
  }
}

async function saveActiveTicket() {
  if (!activeTicket.value || !auth.isAdmin) {
    return;
  }

  ticketSaving.value = true;
  ticketError.value = '';
  ticketNotice.value = '';

  try {
    const response = await apiPut<{ item: SupportTicket }>(`/admin/tickets/${activeTicket.value.id}`, {
      status: activeTicket.value.status,
      priority: activeTicket.value.priority,
      assigned_admin_id: activeTicket.value.assigned_admin_id ?? null,
      admin_note: activeTicket.value.admin_note ?? ''
    });

    activeTicket.value = response.item;

    await loadMyTickets();
    await loadAdminTickets();

    ticketNotice.value = `Ticket #${activeTicket.value.id} updated.`;
  } catch (error) {
    ticketError.value = error instanceof Error ? error.message : 'Unable to update ticket';
  } finally {
    ticketSaving.value = false;
  }
}

async function loadAdminData() {
  if (!isAdmin.value) {
    return;
  }

  const usersResponse = await apiGet<{ items: ManagedUser[] }>('/admin/users');
  users.value = usersResponse.items;

  if (supportScope.value === 'admin') {
    await loadAdminTickets();
  }
}

async function createUser() {
  userSubmitting.value = true;
  userError.value = '';

  try {
    await apiPost('/admin/users', {
      email: newUser.email,
      password: newUser.password,
      role: newUser.role,
      is_seed: newUser.is_seed
    });

    newUser.email = '';
    newUser.password = '';
    newUser.role = 'admin';
    newUser.is_seed = false;

    await loadAdminData();
  } catch (error) {
    userError.value = error instanceof Error ? error.message : 'Unable to create user';
  } finally {
    userSubmitting.value = false;
  }
}

async function saveUser(user: ManagedUser) {
  userError.value = '';

  try {
    const payload: Record<string, unknown> = {
      role: user.role,
      is_active: user.is_active
    };

    if (auth.isOwner) {
      payload.is_seed = user.is_seed;
    }

    await apiPut(`/admin/users/${user.id}`, payload);

    await loadAdminData();
  } catch (error) {
    userError.value = error instanceof Error ? error.message : 'Unable to update user';
  }
}

onMounted(async () => {
  loadPreferences();

  await loadEmailPreferences();

  try {
    await loadMyTickets();
  } catch (error) {
    ticketError.value = error instanceof Error ? error.message : 'Unable to load support tickets';
  }

  try {
    await loadAdminData();
  } catch (error) {
    userError.value = error instanceof Error ? error.message : 'Unable to load admin data';
  }
});
</script>
