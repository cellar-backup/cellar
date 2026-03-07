import { defineStore } from "pinia";
import { ref } from "vue";
import api from "@/lib/api";

export interface Profile {
  id: string;
  name: string;
  type: "schedule" | "retention";
  config: Record<string, unknown>;
  is_default: boolean;
  created_at: string;
  updated_at: string;
}

export const useSettingsStore = defineStore("settings", () => {
  const profiles = ref<Profile[]>([]);
  const settings = ref<Record<string, string>>({});
  const loading = ref(false);

  async function fetchProfiles(type?: string) {
    const params = type ? { type } : {};
    const { data } = await api.get("/profiles", { params });
    profiles.value = Array.isArray(data) ? data : (data.data ?? data);
  }

  async function createProfile(payload: {
    name: string;
    type: "schedule" | "retention";
    config: Record<string, unknown>;
    is_default?: boolean;
  }): Promise<Profile> {
    const { data } = await api.post("/profiles", payload);
    await fetchProfiles();
    return data;
  }

  async function updateProfile(
    id: string,
    payload: Partial<{
      name: string;
      config: Record<string, unknown>;
      is_default: boolean;
    }>,
  ): Promise<Profile> {
    const { data } = await api.put(`/profiles/${id}`, payload);
    await fetchProfiles();
    return data;
  }

  async function deleteProfile(id: string): Promise<void> {
    await api.delete(`/profiles/${id}`);
    profiles.value = profiles.value.filter((p) => p.id !== id);
  }

  async function fetchSettings() {
    const { data } = await api.get("/settings");
    settings.value = data ?? {};
  }

  async function saveSettings(
    entries: { key: string; value: string }[],
  ): Promise<void> {
    const { data } = await api.put("/settings", { settings: entries });
    settings.value = data ?? {};
  }

  return {
    profiles,
    settings,
    loading,
    fetchProfiles,
    createProfile,
    updateProfile,
    deleteProfile,
    fetchSettings,
    saveSettings,
  };
});
