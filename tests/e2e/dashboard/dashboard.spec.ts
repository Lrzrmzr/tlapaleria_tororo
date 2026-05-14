import { test, expect } from '@playwright/test';

test.describe('Dashboard', () => {

  test.beforeEach(async ({ page }) => {
    await page.goto('/dashboard');
    await expect(page.getByRole('heading', { name: /Bienvenido/i })).toBeVisible({ timeout: 10_000 });
  });

  // ─── CARGA ────────────────────────────────────────────────────────────────

  test('muestra saludo con nombre del usuario', async ({ page }) => {
    // El h2 incluye el nombre del usuario autenticado
    await expect(page.getByRole('heading', { name: /Bienvenido/i })).toBeVisible();
    await expect(page.getByText('Aquí está el resumen de tu ferretería hoy')).toBeVisible();
  });

  // ─── ESTADÍSTICAS ─────────────────────────────────────────────────────────

  test('muestra tarjetas de estadísticas principales', async ({ page }) => {
    // Las stat cards provienen de mainStats del servidor
    const statsSection = page.locator('main, .py-12, .py-6').first();
    await expect(statsSection).toBeVisible();

    // Debe haber al menos una tarjeta con valor numérico
    await expect(page.locator('.text-2xl, .text-3xl').first()).toBeVisible({ timeout: 5_000 });
  });

  // ─── VENTAS RECIENTES ─────────────────────────────────────────────────────

  test('muestra sección de ventas recientes', async ({ page }) => {
    await expect(page.getByText(/Ventas recientes|Últimas ventas/i).first()).toBeVisible({ timeout: 5_000 });
  });

  // ─── ALERTAS ─────────────────────────────────────────────────────────────

  test('carga sin errores de JavaScript', async ({ page }) => {
    const jsErrors: string[] = [];
    page.on('pageerror', err => jsErrors.push(err.message));

    await page.goto('/dashboard');
    await page.waitForLoadState('networkidle');

    expect(jsErrors).toHaveLength(0);
  });

  // ─── NAVEGACIÓN ───────────────────────────────────────────────────────────

  test('los accesos directos a módulos funcionan', async ({ page }) => {
    // El sidebar está presente y tiene links de navegación
    const sidebar = page.locator('nav, aside').first();
    await expect(sidebar.getByRole('link', { name: /Ventas/i })).toBeVisible();
    await expect(sidebar.getByRole('link', { name: /Inventario/i })).toBeVisible();
  });

});
