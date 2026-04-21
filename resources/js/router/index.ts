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
      name: "backups",
      component: () => import("@/views/BackupsView.vue"),
    },
    {
      path: "/sources",
      redirect: "/",
    },
    {
      path: "/plans",
      redirect: "/",
    },
    {
      path: "/archives",
      redirect: "/",
    },
    {
      path: "/dashboard",
      redirect: "/",
    },
    {
      path: "/jobs",
      name: "jobs",
      component: () => import("@/views/JobsView.vue"),
    },
    {
      path: "/schedule",
      name: "schedule",
      component: () => import("@/views/ScheduleView.vue"),
    },
    {
      path: "/storage",
      name: "storage",
      component: () => import("@/views/StorageView.vue"),
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
  if (to.meta.public) return;

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

  const auth = useAuthStore();

  // On first load, validate the stored token against the backend
  if (!auth.ready) {
    const valid = await auth.checkAuth();
    if (!valid) {
      return { name: "login" };
    }
    // Valid — allow navigation to proceed
    return;
  }

  // Subsequent navigations — just check in-memory state
  if (!auth.isAuthenticated) {
    return { name: "login" };
  }
});

export default router;
