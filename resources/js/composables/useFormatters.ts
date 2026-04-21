/**
 * Shared formatting helpers for sizes, timestamps, and retention policies.
 */

export function fmtSize(bytes: number | null): string {
  if (bytes === null || bytes === undefined) return "—";
  const units = ["B", "KB", "MB", "GB", "TB"];
  let i = 0;
  let size = bytes;
  while (size >= 1024 && i < units.length - 1) {
    size /= 1024;
    i++;
  }
  return size.toFixed(i === 0 ? 0 : 1) + " " + units[i];
}

export function fmtTime(dateStr: string | null): string {
  if (!dateStr) return "—";
  try {
    return new Date(dateStr).toLocaleString(undefined, {
      month: "short",
      day: "numeric",
      hour: "2-digit",
      minute: "2-digit",
    });
  } catch {
    return dateStr;
  }
}

const RETENTION_LABELS: Record<string, string> = {
  keep_hourly: "hourly",
  keep_daily: "daily",
  keep_weekly: "weekly",
  keep_monthly: "monthly",
  keep_yearly: "yearly",
};

export function fmtRetention(
  policy: Record<string, number> | null,
): string {
  if (!policy) return "none";
  const parts: string[] = [];
  for (const [key, label] of Object.entries(RETENTION_LABELS)) {
    const val = policy[key];
    if (val && val > 0) {
      parts.push(`${val} ${label}`);
    }
  }
  return parts.length > 0 ? parts.join(", ") : "none";
}

export interface RetentionPreset {
  label: string;
  policy: Record<string, number>;
}

export const RETENTION_PRESETS: RetentionPreset[] = [
  {
    label: "Minimal (7d)",
    policy: {
      keep_daily: 7,
      keep_weekly: 0,
      keep_monthly: 0,
      keep_yearly: 0,
    },
  },
  {
    label: "Standard (7d/4w/6m)",
    policy: {
      keep_daily: 7,
      keep_weekly: 4,
      keep_monthly: 6,
      keep_yearly: 0,
    },
  },
  {
    label: "Extended (14d/8w/12m/2y)",
    policy: {
      keep_daily: 14,
      keep_weekly: 8,
      keep_monthly: 12,
      keep_yearly: 2,
    },
  },
  {
    label: "Archival (30d/12w/24m/5y)",
    policy: {
      keep_daily: 30,
      keep_weekly: 12,
      keep_monthly: 24,
      keep_yearly: 5,
    },
  },
];
