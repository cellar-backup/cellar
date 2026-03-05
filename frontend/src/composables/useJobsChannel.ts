import { onMounted, onUnmounted } from "vue";
import echo from "@/lib/echo";
import { usePlansStore } from "@/stores/plans";

/**
 * Composable that subscribes to the `jobs` WebSocket channel and pushes
 * real-time job progress/status events into the plans store.
 *
 * Use this in any view that displays backup plan progress (PlansView,
 * DashboardView, JobsView).
 *
 * The channel is public — no auth handshake needed.
 */
export function useJobsChannel() {
  const store = usePlansStore();

  onMounted(() => {
    echo
      .channel("jobs")
      .listen(
        ".job.updated",
        (event: {
          jobId: string;
          planId: string;
          status: string;
          progress: number;
          jobType: string;
          startedAt: string | null;
          finishedAt: string | null;
          errorMessage: string | null;
        }) => {
          store.handleJobEvent(event);
        },
      );
  });

  onUnmounted(() => {
    echo.leaveChannel("jobs");
  });
}
