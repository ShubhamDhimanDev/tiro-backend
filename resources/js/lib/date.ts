const ISO_DATETIME = /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}/;
const DATE_ONLY = /^(\d{4})-(\d{2})-(\d{2})/;

/**
 * Human-readable calendar date (e.g. "1 Jan 2027") from a `Y-m-d` string or
 * an ISO timestamp. Only the date part is read, so a date-cast column that
 * serialises as `2027-01-01T00:00:00.000000Z` never shifts a day by timezone.
 */
export function formatDate(value: string | null | undefined): string {
    if (!value) {
        return '—';
    }

    const match = DATE_ONLY.exec(value);

    if (!match) {
        return value;
    }

    const [, year, month, day] = match;

    return new Date(Date.UTC(+year, +month - 1, +day)).toLocaleDateString(
        'en-AU',
        { day: 'numeric', month: 'short', year: 'numeric', timeZone: 'UTC' },
    );
}

/** Human-readable date and time in the viewer's timezone, from an ISO timestamp. */
export function formatDateTime(value: string | null | undefined): string {
    if (!value) {
        return '—';
    }

    const date = new Date(value);

    return Number.isNaN(date.getTime())
        ? value
        : date.toLocaleString('en-AU', {
              day: 'numeric',
              month: 'short',
              year: 'numeric',
              hour: 'numeric',
              minute: '2-digit',
          });
}

/** True for ISO timestamps like `2027-01-01T00:00:00.000000Z`. */
export function isIsoDateTime(value: unknown): value is string {
    return typeof value === 'string' && ISO_DATETIME.test(value);
}

/** `Y-m-d` for `<input type="date">`, tolerant of full ISO timestamps. */
export function toDateInputValue(value: string | null | undefined): string {
    return value ? value.slice(0, 10) : '';
}
