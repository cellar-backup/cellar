import { defineStore } from "pinia";
import { ref, computed } from "vue";
import api from "@/lib/api";

export const useAuthStore = defineStore("auth", () => {
  const token = ref<string | null>(localStorage.getItem("cellar_access_token"));
  const user = ref<string | null>(localStorage.getItem("cellar_user"));
  const ready = ref(false);

  const isAuthenticated = computed(() => !!token.value);

  /**
   * Validate the stored token against the backend.
   * Called once during app startup (router guard).
   */
  async function checkAuth(): Promise<boolean> {
    if (!token.value) {
      ready.value = true;
      return false;
    }
    try {
      const { data } = await api.get("/auth/me");
      user.value = data.name ?? data.username;
      localStorage.setItem("cellar_user", user.value!);
      ready.value = true;
      return true;
    } catch {
      clearSession();
      ready.value = true;
      return false;
    }
  }

  async function login(username: string, password: string) {
    const { data } = await api.post("/auth/login", { username, password });
    token.value = data.token;
    user.value = data.user?.name ?? username;
    localStorage.setItem("cellar_access_token", data.token);
    localStorage.setItem("cellar_user", data.user?.name ?? username);
  }

  async function logout() {
    try {
      await api.post("/auth/logout");
    } catch {
      // Token may already be invalid — that's fine
    }
    clearSession();
  }

  function clearSession() {
    token.value = null;
    user.value = null;
    localStorage.removeItem("cellar_access_token");
    localStorage.removeItem("cellar_user");
  }

  return {
    token,
    user,
    ready,
    isAuthenticated,
    checkAuth,
    login,
    logout,
    clearSession,
  };
});
