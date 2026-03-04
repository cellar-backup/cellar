import { createRouter, createWebHistory } from "vue-router";

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
      path: "/settings",
      name: "settings",
      component: () => import("@/views/SettingsView.vue"),
    },
  ],
});

// Auth guard
router.beforeEach((to) => {
  const token = localStorage.getItem("cellar_access_token");
  if (!to.meta.public && !token) {
    return { name: "login" };
  }
});

export default router;
