<script setup lang="ts">
import { ref, onMounted } from "vue";
import { useRouter } from "vue-router";
import { useAuthStore } from "@/stores/auth";
import { Wine } from "lucide-vue-next";

const auth = useAuthStore();
const router = useRouter();

const username = ref("admin");
const password = ref("");
const error = ref("");
const loading = ref(false);

// If already authenticated, skip the login screen
onMounted(() => {
  if (auth.isAuthenticated) {
    router.replace("/");
  }
});

async function handleLogin() {
  error.value = "";
  loading.value = true;
  try {
    await auth.login(username.value, password.value);
    router.push("/");
  } catch {
    error.value = "Invalid credentials. Please try again.";
  } finally {
    loading.value = false;
  }
}
</script>

<template>
  <div class="flex min-h-screen items-center justify-center bg-background px-4">
    <div class="w-full max-w-sm">
      <!-- Logo -->
      <div class="mb-8 text-center">
        <div
          class="mx-auto flex h-14 w-14 items-center justify-center rounded-2xl bg-primary"
        >
          <Wine class="h-7 w-7 text-white" />
        </div>
        <h1 class="mt-4 text-2xl font-bold text-text-primary">Cellar</h1>
        <p class="mt-1 text-sm text-text-muted">Your backups, preserved.</p>
      </div>

      <!-- Form -->
      <form
        class="space-y-4 rounded-xl border border-border bg-surface p-6"
        @submit.prevent="handleLogin"
      >
        <div>
          <label
            for="username"
            class="mb-1.5 block text-sm font-medium text-text-primary"
          >
            Username
          </label>
          <input
            id="username"
            v-model="username"
            type="text"
            required
            class="w-full rounded-lg border border-border bg-surface-raised px-3 py-2 text-sm text-text-primary placeholder-text-muted focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary"
            placeholder="admin"
          />
        </div>

        <div>
          <label
            for="password"
            class="mb-1.5 block text-sm font-medium text-text-primary"
          >
            Password
          </label>
          <input
            id="password"
            v-model="password"
            type="password"
            required
            class="w-full rounded-lg border border-border bg-surface-raised px-3 py-2 text-sm text-text-primary placeholder-text-muted focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary"
            placeholder="••••••••"
          />
        </div>

        <div
          v-if="error"
          class="rounded-lg bg-danger/10 px-3 py-2 text-sm text-danger"
        >
          {{ error }}
        </div>

        <button
          type="submit"
          :disabled="loading"
          class="w-full rounded-lg bg-primary px-4 py-2.5 text-sm font-medium text-white hover:bg-primary/90 transition-colors disabled:opacity-50"
        >
          {{ loading ? "Signing in…" : "Sign in" }}
        </button>
      </form>

      <p class="mt-4 text-center text-xs text-text-muted">
        Default credentials: admin / admin
      </p>
    </div>
  </div>
</template>
