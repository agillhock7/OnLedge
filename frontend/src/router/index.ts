import { createRouter, createWebHistory } from 'vue-router';

import { useAuthStore } from '@/stores/auth';
import CaptureView from '@/views/CaptureView.vue';
import DashboardView from '@/views/DashboardView.vue';
import ForgotPasswordView from '@/views/ForgotPasswordView.vue';
import LoginView from '@/views/LoginView.vue';
import ReceiptDetailView from '@/views/ReceiptDetailView.vue';
import ReceiptsView from '@/views/ReceiptsView.vue';
import RegisterView from '@/views/RegisterView.vue';
import ReportsView from '@/views/ReportsView.vue';
import RulesView from '@/views/RulesView.vue';
import SettingsView from '@/views/SettingsView.vue';
import WelcomeView from '@/views/WelcomeView.vue';

const router = createRouter({
  history: createWebHistory(),
  routes: [
    { path: '/', name: 'welcome', component: WelcomeView, meta: { guestOnly: true } },
    { path: '/login', name: 'login', component: LoginView, meta: { guestOnly: true } },
    { path: '/register', name: 'register', component: RegisterView, meta: { guestOnly: true } },
    { path: '/forgot-password', name: 'forgot-password', component: ForgotPasswordView, meta: { guestOnly: true } },
    { path: '/app', redirect: '/app/dashboard', meta: { requiresAuth: true } },
    { path: '/app/dashboard', name: 'dashboard', component: DashboardView, meta: { requiresAuth: true } },
    { path: '/app/capture', name: 'capture', component: CaptureView, meta: { requiresAuth: true } },
    { path: '/app/receipts', name: 'receipts', component: ReceiptsView, meta: { requiresAuth: true } },
    { path: '/app/receipts/:id', name: 'receipt-detail', component: ReceiptDetailView, meta: { requiresAuth: true } },
    { path: '/app/reports', name: 'reports', component: ReportsView, meta: { requiresAuth: true } },
    { path: '/app/rules', name: 'rules', component: RulesView, meta: { requiresAuth: true } },
    { path: '/app/settings', name: 'settings', component: SettingsView, meta: { requiresAuth: true } }
  ]
});

router.beforeEach(async (to) => {
  const auth = useAuthStore();
  await auth.hydrate();

  if (to.meta.requiresAuth && !auth.isAuthenticated) {
    return { name: 'login' };
  }

  if (to.meta.guestOnly && auth.isAuthenticated) {
    return { name: 'dashboard' };
  }

  return true;
});

export default router;
