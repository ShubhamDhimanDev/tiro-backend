/**
 * Client-side slug prefill only — mirrors Laravel's `Str::slug()` closely
 * enough for a "type the name, see a sensible default slug" UX. The server
 * (`Rule::unique` in each FormRequest) is the actual source of truth for
 * validity/uniqueness; this never needs to be byte-for-byte identical.
 */
export function slugify(value: string): string {
    return value
        .toLowerCase()
        .trim()
        .replace(/[^a-z0-9]+/g, '-')
        .replace(/^-+|-+$/g, '');
}
