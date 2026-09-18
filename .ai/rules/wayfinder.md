---
paths:
    - resources/js/actions/**
    - resources/js/routes/**
---

# Wayfinder

## `wayfinder:generate` needs `--with-form` for form-variant typing

Running `php artisan wayfinder:generate` from the CLI without `--with-form`
silently omits form-variant typed helpers (the `.form()` variants), unlike
the Vite plugin (`wayfinder({ formVariants: true })` in `vite.config.ts`)
which always includes them during `npm run dev`/`composer run dev`/build.
A bare manual `wayfinder:generate` therefore regenerates a subset of
`resources/js/actions/**` and `resources/js/routes/**` that's missing
form-variant types, breaking any file that imports them — this silently
broke ~10 unrelated frontend files (caught via `tsc --noEmit`) during
round 2.

Always run `php artisan wayfinder:generate --with-form` when regenerating
manually, or prefer letting the Vite dev/build pipeline regenerate them
instead of running the artisan command by hand.
