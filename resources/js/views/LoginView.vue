<script setup lang="ts">
import { ref, onMounted } from "vue";
import { useRouter } from "vue-router";
import { useAuthStore } from "@/stores/auth";

const auth = useAuthStore();
const router = useRouter();

const username = ref("admin");
const password = ref("");
const error = ref("");
const loading = ref(false);

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
  } catch (e: unknown) {
    if (e && typeof e === "object" && "code" in e && (e as { code: string }).code === "ERR_NETWORK") {
      error.value = "Cannot reach the backend. Is the server running?";
    } else if (e && typeof e === "object" && "response" in e) {
      const status = (e as { response: { status: number } }).response?.status;
      if (status === 401 || status === 422) {
        error.value = "Invalid credentials. Please try again.";
      } else {
        error.value = `Server error (${status}). Please try again later.`;
      }
    } else {
      error.value = "Connection failed. Is the backend running?";
    }
  } finally {
    loading.value = false;
  }
}
</script>

<template>
  <div class="login-page noise-overlay">
    <div class="login-card-wrap">
      <!-- Logo -->
      <div class="login-brand">
        <div class="login-brand-mark">
          <svg width="28" height="28" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round">
            <path d="M3 17V10a7 7 0 0114 0v7" />
            <path d="M7 17v-7a3 3 0 016 0v7" />
            <path d="M10 17v-4" />
          </svg>
        </div>
        <h1 class="login-brand-name">Cellar</h1>
        <p class="login-brand-sub">Your backups, preserved.</p>
      </div>

      <!-- Form -->
      <form class="login-form" @submit.prevent="handleLogin">
        <div class="field">
          <label class="field-label" for="username">Username</label>
          <input
            id="username"
            v-model="username"
            type="text"
            required
            class="field-input"
            placeholder="admin"
          />
        </div>

        <div class="field">
          <label class="field-label" for="password">Password</label>
          <input
            id="password"
            v-model="password"
            type="password"
            required
            class="field-input"
            placeholder="••••••••"
          />
        </div>

        <div v-if="error" class="login-error">
          {{ error }}
        </div>

        <button type="submit" :disabled="loading" class="btn btn-primary login-submit">
          {{ loading ? "Signing in…" : "Sign in" }}
        </button>
      </form>
    </div>
  </div>
</template>

<style scoped>
.login-page {
  min-height: 100vh;
  display: grid;
  place-items: center;
  background: var(--color-background);
  padding: 16px;
}

.login-card-wrap {
  width: 100%;
  max-width: 380px;
}

.login-brand {
  text-align: center;
  margin-bottom: 32px;
}
.login-brand-mark {
  width: 56px;
  height: 56px;
  border-radius: 16px;
  background: var(--color-wine);
  color: oklch(0.97 0.02 80);
  display: grid;
  place-items: center;
  margin: 0 auto;
  box-shadow:
    0 0 0 1px color-mix(in oklch, var(--color-wine) 40%, transparent),
    inset 0 1px 0 oklch(1 0 0 / 0.15),
    0 4px 12px oklch(0 0 0 / 0.2);
}
.login-brand-name {
  font-family: var(--font-display);
  font-size: 28px;
  color: var(--color-text-primary);
  margin-top: 16px;
  letter-spacing: -0.02em;
}
.login-brand-sub {
  font-size: 13px;
  color: var(--color-text-muted);
  margin-top: 4px;
}

.login-form {
  padding: 28px;
  border: 1px solid var(--color-border);
  border-radius: 16px;
  background: var(--color-surface);
  box-shadow: var(--shadow-md);
  display: flex;
  flex-direction: column;
  gap: 18px;
}

.field {
  display: flex;
  flex-direction: column;
}

.login-error {
  padding: 10px 12px;
  border-radius: 8px;
  background: var(--color-danger-soft);
  color: var(--color-danger);
  font-size: 13px;
  border: 1px solid color-mix(in oklch, var(--color-danger) 20%, var(--color-border));
}

.login-submit {
  width: 100%;
  justify-content: center;
  padding: 11px 16px;
  font-size: 14px;
}
.login-submit:disabled {
  opacity: 0.5;
  cursor: not-allowed;
  transform: none;
}
</style>
