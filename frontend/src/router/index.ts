import { createRouter, createWebHistory } from "vue-router";
import { useAuthStore } from "@/stores/auth";
import api from "@/lib/api";

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
      path: "/setup",
      name: "setup",
      component: () => import("@/views/SetupView.vue"),
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
      redirect: "/sources",
    },
    {
      path: "/archives",
      redirect: "/sources",
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

// One-time setup check on app boot
let setupChecked = false;

router.beforeEach(async (to) => {
  // Check if first-time setup is needed (runs once per session)
  if (!setupChecked) {
    setupChecked = true;
    try {
      const { data } = await api.get("/system/health");
      if (data.needs_setup && to.name !== "setup") {
        return { name: "setup" };
      }
    } catch {
      // Health endpoint unreachable — continue normally
    }
  }

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
