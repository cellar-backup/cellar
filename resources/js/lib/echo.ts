import Echo from "laravel-echo";
import Pusher from "pusher-js";

// Make Pusher available globally (required by Laravel Echo)
(window as unknown as Record<string, unknown>).Pusher = Pusher;

/**
 * Singleton Laravel Echo instance connected to the Reverb WebSocket server.
 *
 * - Uses the Pusher protocol (Reverb is Pusher-compatible)
 * - Connects via the reverse proxy on the same host (no CORS issues)
 * - No authentication needed — we use public channels for homelab simplicity
 */

const isTLS = window.location.protocol === "https:";
const port = Number(window.location.port) || (isTLS ? 443 : 80);

const echo = new Echo({
  broadcaster: "reverb",
  key: (window as unknown as Record<string, string>).__REVERB_KEY__ ?? "cellar-key",
  wsHost: window.location.hostname,
  wsPort: port,
  wssPort: port,
  forceTLS: isTLS,
  enabledTransports: ["ws", "wss"],
  disableStats: true,
});

export default echo;
