import axios from "axios";

const api = axios.create({
  baseURL: "/api/v1",
  headers: {
    "Content-Type": "application/json",
  },
});

// Request interceptor — attach JWT token
api.interceptors.request.use((config) => {
  const token = localStorage.getItem("cellar_access_token");
  if (token) {
    config.headers.Authorization = `Bearer ${token}`;
  }
  return config;
});

// Response interceptor — handle 401 (token expired or revoked).
// Uses soft redirect via router instead of destructive window.location.href.
api.interceptors.response.use(
  (response) => response,
  async (error) => {
    if (error.response?.status === 401) {
      // Lazy-import to avoid circular dependency
      const { useAuthStore } = await import("@/stores/auth");
      const auth = useAuthStore();
      auth.clearSession();

      // Soft-redirect via router (no full page reload)
      const { default: router } = await import("@/router");
      if (router.currentRoute.value.name !== "login") {
        router.push({ name: "login" });
      }
    }
    return Promise.reject(error);
  },
);

export default api;
