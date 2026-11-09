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

const postPaths = async (page, requestToken, javaBinary) => page.evaluate(
    async ({ token, binary }) => {
        const formData = new FormData();
        formData.append('javaBinary', binary);
        formData.append('pythonBinary', '/usr/bin/python3');
        formData.append('validatorJar', '/tmp/unauthorized-validator.jar');
        formData.append('repositoryPath', '/tmp/unauthorized-repository');
        formData.append('tempDirectory', '/tmp/unauthorized-temp');

        const headers = token === null ? {} : { requesttoken: token };
        const response = await fetch(window.OC.generateUrl('/apps/inv_validator/admin/paths'), {
            method: 'POST',
            credentials: 'same-origin',
            headers,
            body: formData,
        });

        return response.status;
    },
    { token: requestToken, binary: javaBinary },
);

test('enforces admin authorization and CSRF without changing configuration', async ({ browser }) => {
    const userContext = await browser.newContext();
    const userPage = await userContext.newPage();

    await login(userPage, 'integration-user', 'integration-user-password');
    await userPage.goto('/index.php/apps/inv_validator/');
    await expect(userPage.getByRole('heading', { name: 'INValidator' })).toBeVisible();
    await userPage.locator('#kosit-xml-file').setInputFiles(validInvoicePath);
    await expect(userPage.locator('#kosit-status')).toHaveText('XML ist gültig.');

    const userRequestToken = await userPage.evaluate(() => window.OC.requestToken);
    const unauthorizedJava = `${nextcloudRoot}/data/unauthorized-java`;
    const unauthorizedStatus = await postPaths(
        userPage,
        userRequestToken,
        unauthorizedJava,
    );
    expect(unauthorizedStatus).toBe(403);
    await userContext.close();

    const adminContext = await browser.newContext();
    const adminPage = await adminContext.newPage();

    await login(adminPage, 'admin', 'integration-test-password');
    await adminPage.goto('/index.php/settings/admin/inv_validator');
    await expect(adminPage.locator('#kosit-admin')).toBeVisible();

    const javaBinary = adminPage.locator('input[name="javaBinary"]');
    const originalJavaBinary = await javaBinary.inputValue();
    expect(originalJavaBinary).not.toBe(unauthorizedJava);

    const missingTokenStatus = await postPaths(adminPage, null, `${nextcloudRoot}/data/missing-token-java`);
    expect(missingTokenStatus).toBe(412);

    const invalidTokenStatus = await postPaths(adminPage, 'invalid-request-token', `${nextcloudRoot}/data/invalid-token-java`);
    expect(invalidTokenStatus).toBe(412);

    await adminPage.reload();
    await expect(adminPage.locator('input[name="javaBinary"]'))
        .toHaveValue(originalJavaBinary);

    await adminContext.close();
});
