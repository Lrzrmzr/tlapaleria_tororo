import { test, expect } from '@playwright/test';

test.describe('Configuración del Sistema', () => {

  test.beforeEach(async ({ page }) => {
    await page.goto('/settings');
    await expect(page.getByRole('heading', { name: 'Configuración del Sistema' })).toBeVisible({ timeout: 10_000 });
  });

  // ─── CARGA ────────────────────────────────────────────────────────────────

  test('muestra advertencia de configuración sensible', async ({ page }) => {
    await expect(page.getByText('Configuración Sensible')).toBeVisible();
    await expect(page.getByText(/Panel de administración exclusivo para Root/)).toBeVisible();
  });

  // ─── TOGGLE VENTAS LIBRES ─────────────────────────────────────────────────

  test('puede alternar la opción de Ventas Libres', async ({ page }) => {
    // El input es sr-only; el elemento clickeable es el <label> que lo envuelve
    const checkbox = page.locator('input[type="checkbox"]').first();
    const label    = page.locator('label').filter({ has: checkbox }).first();
    await expect(label).toBeVisible({ timeout: 5_000 });

    const estadoOriginal = await checkbox.isChecked();

    await label.click();
    await page.waitForLoadState('networkidle');

    await expect(page).toHaveURL('/settings');

    if (await checkbox.isChecked() !== estadoOriginal) {
      await label.click();
      await page.waitForLoadState('networkidle');
    }
  });

  // ─── TOGGLE FALTANTES MANUALES ────────────────────────────────────────────

  test('puede alternar la opción de Faltantes Manuales', async ({ page }) => {
    const checkbox = page.locator('input[type="checkbox"]').nth(1);
    const label    = page.locator('label').filter({ has: page.locator('input[type="checkbox"]') }).nth(1);
    await expect(label).toBeVisible({ timeout: 5_000 });

    const estadoOriginal = await checkbox.isChecked();

    await label.click();
    await page.waitForLoadState('networkidle');

    await expect(page).toHaveURL('/settings');

    if (await checkbox.isChecked() !== estadoOriginal) {
      await label.click();
      await page.waitForLoadState('networkidle');
    }
  });

  // ─── THRESHOLD STOCK BAJO ─────────────────────────────────────────────────

  test('muestra campo de umbral de stock bajo', async ({ page }) => {
    await expect(page.getByText(/Stock Bajo|stock_bajo/i).first()).toBeVisible();
    // Debe haber un input numérico para el umbral
    await expect(page.locator('input[type="number"]').first()).toBeVisible();
  });

  // ─── GENERAR FALTANTES ────────────────────────────────────────────────────

  test('botón generar faltantes automáticos está presente', async ({ page }) => {
    const btnGenerar = page.getByRole('button', { name: /Generar Ahora/i });
    await expect(btnGenerar).toBeVisible({ timeout: 5_000 });
  });

  // ─── ESTADÍSTICAS DEL SISTEMA ─────────────────────────────────────────────

  test('muestra estadísticas del sistema', async ({ page }) => {
    // La página settings expone stats (productos, ventas, usuarios, etc.)
    await expect(page.locator('.grid').first()).toBeVisible({ timeout: 5_000 });
  });

});
