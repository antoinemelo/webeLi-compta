const ISO_DATE_PREFIX = /^(\d{4})-(\d{2})-(\d{2})(?=$|[T\s])/;

/**
 * Formats an API date without constructing a Date object. This avoids shifting a
 * calendar date when the browser and the dossier use different time zones.
 */
export function formatDate(
  value: string | null | undefined,
  fallback = '—'
): string {
  const source = String(value ?? '').trim();
  if (source === '') return fallback;

  const match = source.match(ISO_DATE_PREFIX);
  if (!match) return source;

  return `${match[3]}-${match[2]}-${match[1]}`;
}

/** Formats an API timestamp as JJ-MM-YYYY HH:mm while preserving its time. */
export function formatDateTime(
  value: string | null | undefined,
  fallback = '—'
): string {
  const source = String(value ?? '').trim();
  if (source === '') return fallback;

  const match = source.match(ISO_DATE_PREFIX);
  if (!match) return source;

  const date = `${match[3]}-${match[2]}-${match[1]}`;
  const time = source.match(/[T\s](\d{2}):(\d{2})/);
  return time ? `${date} ${time[1]}:${time[2]}` : date;
}
