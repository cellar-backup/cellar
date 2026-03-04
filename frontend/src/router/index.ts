import { createRouter, createWebHistory } from "vue-router";
import { useAuthStore } from "@/stores/auth";

const router = createRouter({
  history: createWebHistory(),
  routes: [
    {
      path: "/login",
      name: "login",
      component: () => import("@/views/LoginView.vue"),
      meta: { public: true },
    },
    {
      path: "/",
      name: "dashboard",
      component: () => import("@/views/DashboardView.vue"),
    },
    {
      path: "/sources",
      name: "sources",
      component: () => import("@/views/SourcesView.vue"),
    },
    {
      path: "/plans",
      name: "plans",
      component: () => import("@/views/PlansView.vue"),
    },
    {
      path: "/archives",
      name: "archives",
      component: () => import("@/views/ArchivesView.vue"),
    },
    {
      path: "/jobs",
      name: "jobs",
      component: () => import("@/views/JobsView.vue"),
    },
    {
      path: "/radar",
      name: "radar",
      component: () => import("@/views/RadarView.vue"),
    },
    {
      path: "/settings",
      name: "settings",
      component: () => import("@/views/SettingsView.vue"),
    },
  ],
});

// Auth guard — validates the token on first navigation (page load / refresh),
// then trusts the in-memory flag for subsequent in-app navigations.
router.beforeEach(async (to) => {
  if (to.meta.public) return;

  const auth = useAuthStore();

  // On first load, validate the stored token against the backend
  if (!auth.ready) {
    const valid = await auth.checkAuth();
    if (!valid) return { name: "login" };
    return;
  }

  // Subsequent navigations — just check in-memory state
  if (!auth.isAuthenticated) {
    return { name: "login" };
  }
});

export default router;
