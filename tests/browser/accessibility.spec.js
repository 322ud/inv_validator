import AxeBuilder from '@axe-core/playwright';
import { expect, test } from '@playwright/test';

const loginAsAdmin = async (page) => {
    await page.goto('/index.php/login');
    await page.locator('#user').fill('admin');
    await page.locator('#password').fill('integration-test-password');
    await page.locator('button[type="submit"]').first().click();
    await expect(page).not.toHaveURL(/\/login(?:\?|$)/);
};

const assertNoAccessibilityViolations = async (page, selector) => {
    const results = await new AxeBuilder({ page })
        .include(selector)
        .withTags([
            'wcag2a',
            'wcag2aa',
            'wcag21a',
            'wcag21aa',
            'wcag22a',
            'wcag22aa',
        ])
        .analyze();
    const details = results.violations.map((violation) => ({
        id: violation.id,
        impact: violation.impact,
        nodes: violation.nodes.map((node) => node.target),
    }));

    expect(results.violations, JSON.stringify(details, null, 2)).toEqual([]);
};

test('validation UI meets WCAG rules and supports keyboard file selection', async ({ page }) => {
    await loginAsAdmin(page);
    await page.goto('/index.php/apps/kosit_validator/');
    await expect(page.locator('#kosit-validator')).toBeVisible();

    await assertNoAccessibilityViolations(page, '#kosit-validator');

    const dropZone = page.locator('#kosit-drop-zone');
    await dropZone.focus();
    await expect(dropZone).toBeFocused();

    const fileChooserPromise = page.waitForEvent('filechooser');
    await dropZone.press('Enter');
    const fileChooser = await fileChooserPromise;
    expect(fileChooser.isMultiple()).toBe(false);
    await fileChooser.setFiles([]);
});

test('admin UI meets WCAG rules and moves focus into the scenario editor', async ({ page }) => {
    await loginAsAdmin(page);
    await page.goto('/index.php/settings/admin/kosit_validator');
    await expect(page.locator('#kosit-admin')).toBeVisible();

    await page.locator('#kosit-edit-scenario').click();
    await expect(page.locator('#kosit-scenario-editor')).toBeVisible();
    await expect(page.locator('#kosit-scenario-content')).toBeFocused();

    await assertNoAccessibilityViolations(page, '#kosit-admin');
});
