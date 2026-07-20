/** Small formatting helpers shared across pages (dates, disk sizes). */

const RTF = new Intl.RelativeTimeFormat('en', { numeric: 'auto' });

const UNITS: Array<[Intl.RelativeTimeFormatUnit, number]> = [
  ['year', 60 * 60 * 24 * 365],
  ['month', 60 * 60 * 24 * 30],
  ['week', 60 * 60 * 24 * 7],
  ['day', 60 * 60 * 24],
  ['hour', 60 * 60],
  ['minute', 60],
];

export function formatRelativeTime(iso: string | null | undefined): string {
  if (!iso) return '—';
  const diffSec = Math.round((new Date(iso).getTime() - Date.now()) / 1000);

  for (const [unit, secondsInUnit] of UNITS) {
    if (Math.abs(diffSec) >= secondsInUnit) {
      return RTF.format(Math.round(diffSec / secondsInUnit), unit);
    }
  }

  return RTF.format(diffSec, 'second');
}

export function formatDateTime(iso: string | null | undefined): string {
  if (!iso) return '—';
  return new Date(iso).toLocaleString();
}

/**
 * Shorten a release version for display. Only collapses values that look like a
 * git commit SHA (a long run of hex, e.g. the 40-char `github.sha`) down to its
 * first 8 chars; human labels/tags like `v1.2.3` or `20260720_001` are left
 * untouched. Storage is unaffected — this is display-only.
 */
export function shortRef(version: string | null | undefined): string {
  if (!version) return '—';
  return /^[0-9a-f]{12,}$/i.test(version) ? version.slice(0, 8) : version;
}

/** True when `shortRef` would actually shorten this value (i.e. it's a long SHA). */
export function isShortenedRef(version: string | null | undefined): boolean {
  return !!version && shortRef(version) !== version;
}

/** Humanize a millisecond duration: `840 ms`, `2.5s`, `1m 5s`. */
export function formatDuration(ms: number | null | undefined): string {
  if (ms == null) return '—';
  if (ms < 1000) return `${ms} ms`;
  const seconds = ms / 1000;
  if (seconds < 60) return `${seconds.toFixed(seconds < 10 ? 1 : 0)}s`;
  const minutes = Math.floor(seconds / 60);
  const remSeconds = Math.round(seconds % 60);
  return `${minutes}m ${remSeconds}s`;
}

export function formatBytes(bytes: number | null | undefined): string {
  if (bytes == null) return '—';
  const units = ['B', 'KB', 'MB', 'GB', 'TB'];
  let value = bytes;
  let unitIndex = 0;
  while (value >= 1024 && unitIndex < units.length - 1) {
    value /= 1024;
    unitIndex += 1;
  }
  return `${value.toFixed(unitIndex > 0 ? 1 : 0)} ${units[unitIndex]}`;
}
