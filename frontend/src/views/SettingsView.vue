<template>
  <section class="page settings-page">
    <header class="settings-header card">
      <p class="kicker">Operations Console</p>
      <h1>Settings</h1>
      <p class="muted">Account, runtime diagnostics, support workflow, and admin controls.</p>
    </header>

    <div class="grid" style="margin-top: 1rem">
      <article class="card">
        <h3>Runtime</h3>
        <p><strong>API Base:</strong> {{ apiBase }}</p>
        <p><strong>Online:</strong> {{ online ? 'Yes' : 'No' }}</p>
        <p><strong>PWA Worker:</strong> {{ pwaReady ? 'Connected' : 'Not controlling current page' }}</p>
      </article>

      <article class="card">
        <h3>Account</h3>
        <p><strong>Email:</strong> {{ auth.user?.email || '-' }}</p>
        <p><strong>Role:</strong> {{ auth.user?.role || '-' }}</p>
        <p><strong>Seed User:</strong> {{ auth.user?.is_seed ? 'Yes' : 'No' }}</p>
        <p><strong>Status:</strong> {{ auth.user?.is_active ? 'Active' : 'Disabled' }}</p>
      </article>
    </div>

    <article class="card" style="margin-top: 1rem">
      <h3>Support Tickets</h3>
      <p class="muted">Open a ticket for account or receipt processing support.</p>

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

        <div style="grid-column: 1 / -1">
          <label for="ticket-message">Message</label>
          <textarea id="ticket-message" v-model="ticketForm.message" minlength="10" required></textarea>
        </div>

        <div style="grid-column: 1 / -1" class="inline">
          <button class="primary" :disabled="ticketSubmitting">{{ ticketSubmitting ? 'Submitting...' : 'Create Ticket' }}</button>
        </div>
      </form>

      <p v-if="ticketNotice" class="success">{{ ticketNotice }}</p>
      <p v-if="ticketError" class="error">{{ ticketError }}</p>

      <div class="settings-list" v-if="myTickets.length">
        <article class="settings-list-item" v-for="ticket in myTickets" :key="ticket.id">
          <div class="inline" style="justify-content: space-between; width: 100%">
            <strong>#{{ ticket.id }} · {{ ticket.subject }}</strong>
            <span class="pill">{{ ticket.status }}</span>
          </div>
          <p class="muted" style="margin-top: 0.4rem">Priority: {{ ticket.priority }} · {{ formatDate(ticket.created_at) }}</p>
          <p>{{ ticket.message }}</p>
          <p v-if="ticket.admin_note" class="muted"><strong>Admin note:</strong> {{ ticket.admin_note }}</p>
        </article>
      </div>
      <p v-else class="muted">No tickets yet.</p>
    </article>

    <section v-if="auth.isAdmin" style="margin-top: 1rem">
      <article class="card">
        <h3>Admin: User Management</h3>
        <p class="muted">Create admin/owner accounts and disable seed users when migration is complete.</p>

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

          <div class="inline" style="align-items: center; margin-top: 1.6rem">
            <input id="admin-seed" type="checkbox" v-model="newUser.is_seed" :disabled="!auth.isOwner" style="width: auto" />
            <label for="admin-seed" style="margin: 0">Mark as seed user</label>
          </div>

          <div style="grid-column: 1 / -1" class="inline">
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

              <div class="inline" style="align-items: center; margin-top: 1.6rem">
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

      <article class="card" style="margin-top: 1rem">
        <h3>Admin: Support Queue</h3>

        <div class="settings-list" v-if="adminTickets.length">
          <article class="settings-list-item" v-for="ticket in adminTickets" :key="ticket.id">
            <div class="inline" style="justify-content: space-between; width: 100%">
              <strong>#{{ ticket.id }} · {{ ticket.subject }}</strong>
              <span class="pill">{{ ticket.status }}</span>
            </div>
            <p class="muted" style="margin-top: 0.4rem">
              Reporter: {{ ticket.reporter_email || 'unknown' }} · Priority: {{ ticket.priority }}
            </p>
            <p>{{ ticket.message }}</p>

            <div class="settings-form" style="margin-top: 0.8rem">
              <div>
                <label>Status</label>
                <select v-model="ticket.status">
                  <option value="open">Open</option>
                  <option value="in_progress">In Progress</option>
                  <option value="resolved">Resolved</option>
                  <option value="closed">Closed</option>
                </select>
              </div>

              <div>
                <label>Priority</label>
                <select v-model="ticket.priority">
                  <option value="low">Low</option>
                  <option value="normal">Normal</option>
                  <option value="high">High</option>
                  <option value="urgent">Urgent</option>
                </select>
              </div>

              <div style="grid-column: 1 / -1">
                <label>Admin Note</label>
                <textarea v-model="ticket.admin_note"></textarea>
              </div>

              <div class="inline" style="align-items: end">
                <button class="secondary" @click.prevent="saveAdminTicket(ticket)">Save Ticket</button>
              </div>
            </div>
          </article>
        </div>
        <p v-else class="muted">No support tickets in queue.</p>
      </article>
    </section>
  </section>
</template>

<script setup lang="ts">
import { computed, onMounted, onUnmounted, reactive, ref } from 'vue';

import { apiGet, apiPost, apiPut } from '@/services/api';
import { useAuthStore } from '@/stores/auth';

type Role = 'user' | 'admin' | 'owner';

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
  status: 'open' | 'in_progress' | 'resolved' | 'closed';
  priority: 'low' | 'normal' | 'high' | 'urgent';
  assigned_admin_id?: number | null;
  assigned_admin_email?: string | null;
  reporter_email?: string | null;
  admin_note?: string | null;
  created_at: string;
  updated_at: string;
};

const auth = useAuthStore();
const apiBase = import.meta.env.VITE_API_BASE_URL || '/api';
const online = ref(navigator.onLine);
const pwaReady = ref('serviceWorker' in navigator && Boolean(navigator.serviceWorker?.controller));

const ticketSubmitting = ref(false);
const ticketError = ref('');
const ticketNotice = ref('');
const myTickets = ref<SupportTicket[]>([]);
const adminTickets = ref<SupportTicket[]>([]);
const users = ref<ManagedUser[]>([]);
const userSubmitting = ref(false);
const userError = ref('');

const ticketForm = reactive({
  subject: '',
  message: '',
  priority: 'normal' as SupportTicket['priority']
});

const newUser = reactive({
  email: '',
  password: '',
  role: 'admin' as Role,
  is_seed: false
});

const isAdmin = computed(() => auth.isAdmin);

function onConnectivity() {
  online.value = navigator.onLine;
}

function formatDate(value: string): string {
  try {
    return new Date(value).toLocaleString();
  } catch {
    return value;
  }
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

async function loadMyTickets() {
  const response = await apiGet<{ items: SupportTicket[] }>('/support/tickets/my');
  myTickets.value = response.items;
}

async function loadAdminData() {
  if (!isAdmin.value) {
    return;
  }

  const [usersResponse, ticketsResponse] = await Promise.all([
    apiGet<{ items: ManagedUser[] }>('/admin/users'),
    apiGet<{ items: SupportTicket[] }>('/admin/tickets')
  ]);

  users.value = usersResponse.items;
  adminTickets.value = ticketsResponse.items;
}

async function createTicket() {
  ticketSubmitting.value = true;
  ticketError.value = '';
  ticketNotice.value = '';

  try {
    await apiPost('/support/tickets', {
      subject: ticketForm.subject,
      message: ticketForm.message,
      priority: ticketForm.priority
    });

    ticketForm.subject = '';
    ticketForm.message = '';
    ticketForm.priority = 'normal';
    ticketNotice.value = 'Support ticket created.';
    await loadMyTickets();

    if (isAdmin.value) {
      await loadAdminData();
    }
  } catch (error) {
    ticketError.value = error instanceof Error ? error.message : 'Unable to create ticket';
  } finally {
    ticketSubmitting.value = false;
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
    await apiPut(`/admin/users/${user.id}`, {
      role: user.role,
      is_active: user.is_active,
      is_seed: user.is_seed
    });

    await loadAdminData();
  } catch (error) {
    userError.value = error instanceof Error ? error.message : 'Unable to update user';
  }
}

async function saveAdminTicket(ticket: SupportTicket) {
  try {
    await apiPut(`/admin/tickets/${ticket.id}`, {
      status: ticket.status,
      priority: ticket.priority,
      admin_note: ticket.admin_note ?? ''
    });

    await loadAdminData();
    await loadMyTickets();
  } catch (error) {
    ticketError.value = error instanceof Error ? error.message : 'Unable to update ticket';
  }
}

onMounted(async () => {
  window.addEventListener('online', onConnectivity);
  window.addEventListener('offline', onConnectivity);

  if ('serviceWorker' in navigator) {
    navigator.serviceWorker.ready.then(() => {
      pwaReady.value = true;
    }).catch(() => {
      pwaReady.value = Boolean(navigator.serviceWorker?.controller);
    });
  }

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

onUnmounted(() => {
  window.removeEventListener('online', onConnectivity);
  window.removeEventListener('offline', onConnectivity);
});
</script>
