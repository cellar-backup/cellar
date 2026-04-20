import { ref, watch } from "vue";

export type Theme = "light" | "dark";
export type Motion = "full" | "subtle" | "none";

const theme = ref<Theme>(
  (localStorage.getItem("cellar-theme") as Theme) || "dark",
);
const motion = ref<Motion>(
  (localStorage.getItem("cellar-motion") as Motion) || "full",
);

// Sync to DOM on init
function syncTheme() {
  document.body.setAttribute("data-theme", theme.value);
  localStorage.setItem("cellar-theme", theme.value);
}

function syncMotion() {
  document.body.setAttribute("data-motion", motion.value);
  localStorage.setItem("cellar-motion", motion.value);
}

// Initial sync
syncTheme();
syncMotion();

watch(theme, syncTheme);
watch(motion, syncMotion);

export function useTheme() {
  function toggleTheme() {
    theme.value = theme.value === "dark" ? "light" : "dark";
  }

  function setTheme(t: Theme) {
    theme.value = t;
  }

  function setMotion(m: Motion) {
    motion.value = m;
  }

  return {
    theme,
    motion,
    toggleTheme,
    setTheme,
    setMotion,
  };
}
