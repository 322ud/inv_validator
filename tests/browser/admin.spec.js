import { expect, test } from '@playwright/test';

const nextcloudRoot = process.env.NEXTCLOUD_ROOT;

if (!nextcloudRoot) {
    throw new Error('NEXTCLOUD_ROOT must be configured');
}

const loginAsAdmin = async (page) => {
    await page.goto('/index.php/login');
    await page.locator('#user').fill('admin');
    await page.locator('#password').fill('integration-test-password');
    await page.locator('button[type="submit"]').first().click();
    await expect(page).not.toHaveURL(/\/login(?:\?|$)/);
};

const savePaths = async (page) => {
    const responsePromise = page.waitForResponse((response) => (
        response.request().method() === 'POST'
        && response.url().endsWith('/apps/inv_validator/admin/paths')
    ));

    await page.locator('#kosit-save-paths').click();
    const response = await responsePromise;

    expect(response.status()).toBe(200);
    expect(response.request().headers().requesttoken).toBeTruthy();
    await expect(page.locator('#kosit-admin-message')).toHaveText('Pfade wurden gespeichert.');
};

test('configures interpreter paths and keeps infrastructure paths read-only', async ({ page }) => {
    const pageErrors = [];
    page.on('pageerror', (error) => pageErrors.push(error.message));

    await loginAsAdmin(page);
    await page.goto('/index.php/settings/admin/inv_validator');
    const admin = page.locator('#kosit-admin');
    await expect(admin).toBeVisible();
    await expect(admin.getByRole('heading', { name: 'INValidator', level: 2 })).toBeVisible();

    await expect(page.locator('input[name="validatorJar"]')).toHaveCount(0);
    await expect(page.locator('input[name="repositoryPath"]')).toHaveCount(0);
    await expect(page.locator('input[name="tempDirectory"]')).toHaveCount(0);

    await expect(admin.getByRole('heading', { name: 'Einstellungen', exact: true })).toBeVisible();
    await expect(admin.locator('table.kosit-system')).toHaveCount(5);
    const pythonBinary = page.locator('input[name="pythonBinary"]');
    const originalPythonBinary = await pythonBinary.inputValue();
    const browserPythonBinary = `${nextcloudRoot}/data/test-python`;

    await pythonBinary.fill(browserPythonBinary);
    await savePaths(page);
    await expect(page.locator('input[name="pythonBinary"]'))
        .toHaveValue(browserPythonBinary);

    const readme = admin.locator('.kosit-readme-rich');
    await expect(readme).toBeVisible();
    await expect(admin.locator('.kosit-readme-fallback')).toBeHidden();
    await expect(readme.getByRole('heading', { name: 'INValidator', exact: true })).toBeVisible();

    await page.locator('input[name="pythonBinary"]').fill(originalPythonBinary);
    await savePaths(page);
    await expect(page.locator('input[name="pythonBinary"]'))
        .toHaveValue(originalPythonBinary);

    await expect(pageErrors).toEqual([]);
});

test('opens local administration help and its requested chapter', async ({ page }) => {
    await loginAsAdmin(page);
    await page.goto('/index.php/settings/admin/inv_validator');
    const link = page.locator('#kosit-paths-form [data-kosit-help]').first();
    const href = await link.getAttribute('href');
    expect(href).toContain('/apps/inv_validator/readme?document=admin#h-java-installieren');
    const response = await page.request.get(href.split('#')[0]);
    expect(response.status()).toBe(200);
    const popupPromise = page.waitForEvent('popup');
    await link.click();
    const popup = await popupPromise;
    await expect(popup.locator('.kosit-readme-page')).toBeVisible();
    await expect(popup.locator('[id="h-java-installieren"]')).toBeInViewport();
    const viewer = popup.locator('.kosit-readme-page > .kosit-readme-viewer');
    await expect.poll(() => viewer.evaluate(el => el.scrollHeight > el.clientHeight)).toBe(true);
    // Home starts a keyboard scroll animation that can swallow the following
    // wheel input in Chromium, even after scrollTop is reset synchronously.
    await viewer.evaluate(el => { el.scrollTop = 0; });
    await viewer.hover();
    await popup.mouse.wheel(0, 500);
    await expect.poll(() => viewer.evaluate(el => el.scrollTop)).toBeGreaterThan(0);
    await viewer.evaluate(el => { el.scrollTop = 0; });
    await viewer.focus();
    await popup.keyboard.press('PageDown');
    await expect.poll(() => viewer.evaluate(el => el.scrollTop)).toBeGreaterThan(0);
    await popup.evaluate(() => { window.location.hash = 'h-optionale-pdf-unterstutzung'; });
    await expect(popup.locator('[id="h-optionale-pdf-unterstutzung"]')).toBeInViewport();
    await popup.close();
});
