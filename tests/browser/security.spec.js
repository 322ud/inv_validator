import { expect, test } from '@playwright/test';

const nextcloudRoot = process.env.NEXTCLOUD_ROOT;
const validInvoicePath = process.env.KOSIT_VALID_INVOICE;

if (!nextcloudRoot || !validInvoicePath) {
    throw new Error('NEXTCLOUD_ROOT and KOSIT_VALID_INVOICE must be configured');
}

const login = async (page, user, password) => {
    await page.goto('/index.php/login');
    await page.locator('#user').fill(user);
    await page.locator('#password').fill(password);
    await page.locator('button[type="submit"]').first().click();
    await expect(page).not.toHaveURL(/\/login(?:\?|$)/);
};

const postPaths = async (page, requestToken, temporaryDirectory) => page.evaluate(
    async ({ token, directory }) => {
        const formData = new FormData();
        formData.append('javaBinary', '/usr/bin/java');
        formData.append('validatorJar', '/tmp/unauthorized-validator.jar');
        formData.append('repositoryPath', '/tmp/unauthorized-repository');
        formData.append('scenarioFile', '/tmp/unauthorized-scenario.xml');
        formData.append('tempDirectory', directory);

        const headers = token === null ? {} : { requesttoken: token };
        const response = await fetch(window.OC.generateUrl('/apps/kosit_validator/admin/paths'), {
            method: 'POST',
            credentials: 'same-origin',
            headers,
            body: formData,
        });

        return response.status;
    },
    { token: requestToken, directory: temporaryDirectory },
);

test('enforces admin authorization and CSRF without changing configuration', async ({ browser }) => {
    const userContext = await browser.newContext();
    const userPage = await userContext.newPage();

    await login(userPage, 'integration-user', 'integration-user-password');
    await userPage.goto('/index.php/apps/kosit_validator/');
    await expect(userPage.getByRole('heading', { name: 'KoSIT Validator' })).toBeVisible();
    await userPage.locator('#kosit-xml-file').setInputFiles(validInvoicePath);
    await expect(userPage.locator('#kosit-status')).toHaveText('XML ist gültig.');

    const userRequestToken = await userPage.evaluate(() => window.OC.requestToken);
    const unauthorizedDirectory = `${nextcloudRoot}/data/unauthorized-temp`;
    const unauthorizedStatus = await postPaths(
        userPage,
        userRequestToken,
        unauthorizedDirectory,
    );
    expect(unauthorizedStatus).toBe(403);
    await userContext.close();

    const adminContext = await browser.newContext();
    const adminPage = await adminContext.newPage();

    await login(adminPage, 'admin', 'integration-test-password');
    await adminPage.goto('/index.php/settings/admin/kosit_validator');
    await expect(adminPage.locator('#kosit-admin')).toBeVisible();

    const temporaryDirectory = adminPage.locator('input[name="tempDirectory"]');
    const originalTemporaryDirectory = await temporaryDirectory.inputValue();
    expect(originalTemporaryDirectory).not.toBe(unauthorizedDirectory);

    const missingTokenDirectory = `${nextcloudRoot}/data/missing-token-temp`;
    const missingTokenStatus = await postPaths(adminPage, null, missingTokenDirectory);
    expect(missingTokenStatus).toBe(412);

    const invalidTokenDirectory = `${nextcloudRoot}/data/invalid-token-temp`;
    const invalidTokenStatus = await postPaths(adminPage, 'invalid-request-token', invalidTokenDirectory);
    expect(invalidTokenStatus).toBe(412);

    const scenarioBefore = await adminPage.evaluate(async () => {
        const response = await fetch(
            window.OC.generateUrl('/apps/kosit_validator/admin/status?scenario=1'),
            {
                credentials: 'same-origin',
                headers: { requesttoken: window.OC.requestToken },
            },
        );

        return response.json();
    });
    expect(scenarioBefore.success).toBe(true);

    const scenarioStatus = await adminPage.evaluate(async (scenario) => {
        const formData = new FormData();
        formData.append('action', 'saveScenario');
        formData.append('content', `${scenario.content}\n`);
        formData.append('checksum', scenario.checksum);

        const response = await fetch(window.OC.generateUrl('/apps/kosit_validator/admin/paths'), {
            method: 'POST',
            credentials: 'same-origin',
            headers: { requesttoken: 'invalid-request-token' },
            body: formData,
        });

        return response.status;
    }, scenarioBefore.scenario);
    expect(scenarioStatus).toBe(412);

    await adminPage.reload();
    await expect(adminPage.locator('input[name="tempDirectory"]'))
        .toHaveValue(originalTemporaryDirectory);

    const scenarioAfter = await adminPage.evaluate(async () => {
        const response = await fetch(
            window.OC.generateUrl('/apps/kosit_validator/admin/status?scenario=1'),
            {
                credentials: 'same-origin',
                headers: { requesttoken: window.OC.requestToken },
            },
        );

        return response.json();
    });
    expect(scenarioAfter.scenario.checksum).toBe(scenarioBefore.scenario.checksum);
    expect(scenarioAfter.scenario.content).toBe(scenarioBefore.scenario.content);

    await adminContext.close();
});
