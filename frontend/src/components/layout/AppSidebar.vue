<script setup lang="ts">
import { ref } from "vue";
import { RouterLink, useRoute, useRouter } from "vue-router";
import { useAuthStore } from "@/stores/auth";
import {
  LayoutDashboard,
  Database,
  Archive,
  ClipboardList,
  ScrollText,
  Settings,
  ChevronLeft,
  LogOut,
  Wine,
} from "lucide-vue-next";

const collapsed = ref(false);
const route = useRoute();
const router = useRouter();
const auth = useAuthStore();

const navItems = [
  { label: "Dashboard", icon: LayoutDashboard, to: "/" },
  { label: "Sources", icon: Database, to: "/sources" },
  { label: "Backup Plans", icon: ClipboardList, to: "/plans" },
  { label: "Archives", icon: Archive, to: "/archives" },
  { label: "Jobs", icon: ScrollText, to: "/jobs" },
  { label: "Settings", icon: Settings, to: "/settings" },
];

function isActive(to: string) {
  if (to === "/") return route.path === "/";
  return route.path.startsWith(to);
}

function handleLogout() {
  auth.logout();
  router.push("/login");
}
</script>

<template>
  <aside
    class="flex h-full flex-col border-r border-border bg-surface transition-all duration-200"
    :class="collapsed ? 'w-16' : 'w-60'"
  >
    <!-- Logo -->
    <div class="flex h-14 items-center gap-3 px-4">
      <div
        class="flex h-8 w-8 items-center justify-center rounded-lg bg-primary text-white"
      >
        <Wine class="h-4 w-4" />
      </div>
      <span v-if="!collapsed" class="text-lg font-semibold text-text-primary">
        Cellar
      </span>
    </div>

    <!-- Nav -->
    <nav class="mt-2 flex-1 space-y-1 px-2">
      <RouterLink
        v-for="item in navItems"
        :key="item.to"
        :to="item.to"
        class="flex items-center gap-3 rounded-lg px-3 py-2 text-sm transition-colors"
        :class="
          isActive(item.to)
            ? 'bg-primary/10 text-primary'
            : 'text-text-muted hover:bg-surface-raised hover:text-text-primary'
        "
      >
        <component :is="item.icon" class="h-5 w-5 shrink-0" />
        <span v-if="!collapsed">{{ item.label }}</span>
      </RouterLink>
    </nav>

    <!-- User / Logout -->
    <div class="border-t border-border px-2 py-2">
      <button
        class="flex w-full items-center gap-3 rounded-lg px-3 py-2 text-sm text-text-muted hover:bg-surface-raised hover:text-text-primary transition-colors"
        @click="handleLogout"
      >
        <LogOut class="h-5 w-5 shrink-0" />
        <span v-if="!collapsed">Sign out</span>
      </button>
    </div>

    <!-- Collapse toggle -->
    <button
      class="flex h-10 items-center justify-center border-t border-border text-text-muted hover:text-text-primary transition-colors"
      @click="collapsed = !collapsed"
    >
      <ChevronLeft
        class="h-4 w-4 transition-transform"
        :class="{ 'rotate-180': collapsed }"
      />
    </button>
  </aside>
</template>
