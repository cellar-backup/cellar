import { ref, computed, watch } from "vue";
import { useSourcesStore } from "@/stores/sources";

const activeDbId = ref<string | null>(
  localStorage.getItem("cellar-active-db") || null,
);

export function useActiveDatabase() {
  const sourcesStore = useSourcesStore();

  const activeDatabase = computed(() =>
    sourcesStore.sources.find((s) => s.id === activeDbId.value) || null,
  );

  function setActiveDatabase(id: string | null) {
    activeDbId.value = id;
    if (id) localStorage.setItem("cellar-active-db", id);
    else localStorage.removeItem("cellar-active-db");
  }

  // Auto-select first if current is invalid
  watch(
    () => sourcesStore.sources,
    (sources) => {
      if (sources.length > 0 && !sources.find((s) => s.id === activeDbId.value)) {
        setActiveDatabase(sources[0].id);
      }
    },
  );

  return {
    activeDbId,
    activeDatabase,
    setActiveDatabase,
  };
}
