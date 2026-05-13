import { test, expect } from '@playwright/test';

/**
 * Tests de autenticación.
 * NO usan storageState — prueban el login en sí.
 */
test.use({ storageState: { cookies: [], origins: [] } });

test.describe('Login', () => {
  test.beforeEach(async ({ page }) => {
    await page.goto('/login');
    await expect(page.locator('h1')).toContainText('Tlapalería TOTORO');
  });

  test('muestra el formulario correctamente', async ({ page }) => {
    await expect(page.locator('#email')).toBeVisible();
    await expect(page.locator('#password')).toBeVisible();
    await expect(page.getByRole('button', { name: /iniciar sesión/i })).toBeVisible();
  });

  test('login exitoso redirige al dashboard', async ({ page }) => {
    await page.fill('#email', 'admin@admin.com');
    await page.fill('#password', 'pasword123');
    await page.getByRole('button', { name: /iniciar sesión/i }).click();

    await page.waitForURL('**/dashboard', { timeout: 10_000 });
    await expect(page).toHaveURL('/dashboard');
  });

  test('credenciales incorrectas muestra error', async ({ page }) => {
    await page.fill('#email', 'admin@admin.com');
    await page.fill('#password', 'contraseña-incorrecta');
    await page.getByRole('button', { name: /iniciar sesión/i }).click();

    const errorDiv = page.locator('div.text-red-600').first();
    await expect(errorDiv).toBeVisible({ timeout: 5_000 });
    await expect(page).toHaveURL('/login');
  });

  test('rutas protegidas redirigen a login si no hay sesión', async ({ page }) => {
    await page.goto('/dashboard');
    await expect(page).toHaveURL('/login');

    await page.goto('/ventas');
    await expect(page).toHaveURL('/login');

    await page.goto('/productos');
    await expect(page).toHaveURL('/login');
  });
});
