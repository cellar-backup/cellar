import { ref, onMounted, onUnmounted } from "vue";

/**
 * Reactive "now" ticker for computing elapsed time in templates.
 * Updates every second while the component is mounted.
 */
export function useElapsedTime() {
  const now = ref(Date.now());
  let timer: ReturnType<typeof setInterval> | null = null;

  onMounted(() => {
    timer = setInterval(() => {
      now.value = Date.now();
    }, 1000);
  });

  onUnmounted(() => {
    if (timer) clearInterval(timer);
  });

  function elapsed(startedAt: string | null): string {
    if (!startedAt) return "";
    const ms = now.value - new Date(startedAt).getTime();
    const secs = Math.max(0, Math.round(ms / 1000));
    if (secs < 60) return secs + "s";
    const mins = Math.floor(secs / 60);
    const rem = secs % 60;
    return mins + "m " + rem + "s";
  }

  function timeAgo(dateStr: string | null): string {
    if (!dateStr) return "never";
    const diff = Date.now() - new Date(dateStr).getTime();
    const mins = Math.floor(diff / 60000);
    if (mins < 1) return "just now";
    if (mins < 60) return mins + "m ago";
    const hrs = Math.floor(mins / 60);
    if (hrs < 24) return hrs + "h ago";
    const days = Math.floor(hrs / 24);
    return days + "d ago";
  }

  return { now, elapsed, timeAgo };
}
