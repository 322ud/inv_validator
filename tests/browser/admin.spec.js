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
        && response.url().endsWith('/apps/kosit_validator/admin/paths')
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
    await page.goto('/index.php/settings/admin/kosit_validator');
    const admin = page.locator('#kosit-admin');
    await expect(admin).toBeVisible();
    await expect(admin.getByRole('heading', { name: 'Anbindung des KoSIT-Validators' })).toBeVisible();

    await expect(page.locator('input[name="validatorJar"]')).toHaveCount(0);
    await expect(page.locator('input[name="repositoryPath"]')).toHaveCount(0);
    await expect(page.locator('input[name="tempDirectory"]')).toHaveCount(0);

    const pythonBinary = page.locator('input[name="pythonBinary"]');
    const originalPythonBinary = await pythonBinary.inputValue();
    const browserPythonBinary = `${nextcloudRoot}/data/test-python`;

    await pythonBinary.fill(browserPythonBinary);
    await savePaths(page);
    await expect(page.locator('input[name="pythonBinary"]'))
        .toHaveValue(browserPythonBinary);

    await page.locator('input[name="pythonBinary"]').fill(originalPythonBinary);
    await savePaths(page);
    await expect(page.locator('input[name="pythonBinary"]'))
        .toHaveValue(originalPythonBinary);

    await expect(pageErrors).toEqual([]);
});
