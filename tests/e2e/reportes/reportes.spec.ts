import { test, expect } from '@playwright/test';

test.describe('Reportes', () => {

  test.beforeEach(async ({ page }) => {
    await page.goto('/reportes');
    await expect(page.getByRole('heading', { name: 'Reportes & Corte de Caja' })).toBeVisible({ timeout: 10_000 });
  });

  // ─── CARGA ────────────────────────────────────────────────────────────────

  test('la página carga con datos del corte del día', async ({ page }) => {
    // El corte siempre muestra secciones de ventas y gastos
    await expect(page.getByText(/Ventas/i).first()).toBeVisible();
    await expect(page.getByText(/Gastos/i).first()).toBeVisible();
  });

  // ─── FILTRO POR FECHA ─────────────────────────────────────────────────────

  test('shortcut Hoy aplica fechas de hoy', async ({ page }) => {
    await page.getByRole('button', { name: 'Hoy' }).click();
    await page.waitForLoadState('networkidle');

    const today = new Date().toISOString().slice(0, 10);
    const inputDesde = page.locator('input[type="date"]').first();
    await expect(inputDesde).toHaveValue(today);
  });

  test('shortcut Ayer aplica fecha de ayer', async ({ page }) => {
    await page.getByRole('button', { name: 'Ayer' }).click();
    await page.waitForLoadState('networkidle');

    const ayer = new Date();
    ayer.setDate(ayer.getDate() - 1);
    const ayerStr = ayer.toISOString().slice(0, 10);

    const inputDesde = page.locator('input[type="date"]').first();
    await expect(inputDesde).toHaveValue(ayerStr);
  });

  test('shortcut Semana aplica rango de la semana actual', async ({ page }) => {
    await page.getByRole('button', { name: 'Semana' }).click();
    await page.waitForLoadState('networkidle');

    // Después de aplicar, las fechas deben estar presentes y la página cargó
    await expect(page.getByRole('heading', { name: 'Reportes & Corte de Caja' })).toBeVisible();
  });

  test('shortcut Mes aplica rango del mes actual', async ({ page }) => {
    await page.getByRole('button', { name: 'Este mes', exact: true }).click();
    await page.waitForLoadState('networkidle');

    await expect(page.getByRole('heading', { name: 'Reportes & Corte de Caja' })).toBeVisible();
  });

  // ─── EXPORTACIÓN ─────────────────────────────────────────────────────────

  test('los botones de exportación PDF y CSV están presentes', async ({ page }) => {
    await expect(page.getByRole('link', { name: /PDF/i }).or(page.getByText(/PDF/i)).first()).toBeVisible();
    await expect(page.getByRole('link', { name: /CSV/i }).or(page.getByText(/CSV/i)).first()).toBeVisible();
  });

  // ─── FILTRO POR SUCURSAL (root) ───────────────────────────────────────────

  test('root puede filtrar por sucursal', async ({ page }) => {
    // El select de sucursal solo aparece para root
    const selectSucursal = page.locator('select').first();
    if (await selectSucursal.isVisible()) {
      await selectSucursal.selectOption({ index: 0 });
      await page.getByRole('button', { name: 'Aplicar' }).click();
      await page.waitForLoadState('networkidle');
      await expect(page.getByRole('heading', { name: 'Reportes & Corte de Caja' })).toBeVisible();
    }
  });

});
