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

test('configures paths and edits the scenario through Nextcloud administration', async ({ page }) => {
    const pageErrors = [];
    page.on('pageerror', (error) => pageErrors.push(error.message));

    await loginAsAdmin(page);
    await page.goto('/index.php/settings/admin/additional');
    await expect(page.locator('#kosit-admin')).toBeVisible();
    await expect(page.getByRole('heading', { name: 'KoSIT Validator' })).toBeVisible();

    const temporaryDirectory = page.locator('input[name="tempDirectory"]');
    const originalTemporaryDirectory = await temporaryDirectory.inputValue();
    const browserTemporaryDirectory = `${nextcloudRoot}/data/kosit-validator-browser-temp`;

    await temporaryDirectory.fill(browserTemporaryDirectory);
    await savePaths(page);
    await expect(page.locator('input[name="tempDirectory"]'))
        .toHaveValue(browserTemporaryDirectory);

    await page.locator('input[name="tempDirectory"]').fill(originalTemporaryDirectory);
    await savePaths(page);
    await expect(page.locator('input[name="tempDirectory"]'))
        .toHaveValue(originalTemporaryDirectory);

    await page.locator('#kosit-edit-scenario').click();
    await expect(page.locator('#kosit-scenario-message'))
        .toHaveText('Szenario-Datei wurde geladen.');
    await expect(page.locator('#kosit-scenario-editor')).toBeVisible();

    const scenarioContent = page.locator('#kosit-scenario-content');
    const originalScenario = await scenarioContent.inputValue();

    await scenarioContent.fill('<scenarios/>');
    await page.locator('#kosit-save-scenario').click();
    await expect(page.locator('#kosit-scenario-message')).toHaveText(
        'Das Wurzelelement muss „scenarios“ im KoSIT-Szenario-Namensraum sein.',
    );
    await expect(scenarioContent).toHaveValue('<scenarios/>');

    const saveScenarioResponse = page.waitForResponse((response) => (
        response.request().method() === 'POST'
        && response.url().endsWith('/apps/kosit_validator/admin/paths')
        && response.status() === 200
    ));
    await scenarioContent.fill(originalScenario);
    await page.locator('#kosit-save-scenario').click();
    await saveScenarioResponse;
    await expect(page.locator('#kosit-scenario-message'))
        .toHaveText('Szenario-Datei wurde gespeichert.');
    await expect(scenarioContent).toHaveValue(originalScenario);
    await expect(pageErrors).toEqual([]);
});
