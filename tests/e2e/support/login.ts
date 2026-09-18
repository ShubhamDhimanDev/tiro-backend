import type { Page } from '@playwright/test';
import { totp } from './totp';

/**
 * Drives the real login + 2FA challenge screens exactly as a staff member
 * would, mirroring `staff-invite-nav.spec.ts`'s inline version of this flow.
 * Extracted here so multiple specs can provision different-role users and
 * log each of them in without duplicating the same five Playwright calls.
 *
 * Never a shortcut around auth (no cookie/session injection) -- the point of
 * these specs is that the real, rendered login + 2FA + nav + form flow
 * works end to end, not just that the destination page can be reached.
 */
export async function loginWithTotp(
    page: Page,
    baseUrl: string,
    email: string,
    password: string,
    totpSecret: string,
): Promise<void> {
    await page.goto(`${baseUrl}/login`);
    await page.getByLabel('Email address').fill(email);
    await page.getByRole('textbox', { name: 'Password' }).fill(password);
    await page.getByRole('button', { name: /log in/i }).click();

    await page.waitForURL(/two-factor/);
    await page.locator('input[name="code"]').fill(totp(totpSecret));
    await page.getByRole('button', { name: /continue/i }).click();

    await page.waitForURL(`${baseUrl}/dashboard`);
}
