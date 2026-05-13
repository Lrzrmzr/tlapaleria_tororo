import { test, expect } from '@playwright/test';

const ts             = Date.now();
const PRODUCTO_NOMBRE = `Inv Test ${ts}`;

test.describe('Inventario', () => {

  // Crea un producto sin inventario antes de los tests
  test.beforeAll(async ({ browser }) => {
    const ctx  = await browser.newContext({ storageState: 'tests/e2e/.auth/user.json' });
    const page = await ctx.newPage();

    await page.goto('http://127.0.0.1:8000/productos');
    await page.getByRole('button', { name: 'Nuevo Producto' }).click();

    const modal = page.locator('[role="dialog"][aria-modal="true"]');
    await expect(modal).toBeVisible();
    await modal.locator('input[type="text"]').nth(0).fill(PRODUCTO_NOMBRE);
    await modal.locator('input[type="number"]').first().fill('99');
    await modal.getByRole('button', { name: 'Guardar Producto' }).click();
    await expect(modal).not.toBeVisible({ timeout: 8_000 });

    await ctx.close();
  });

  // Elimina el producto de prueba al finalizar
  test.afterAll(async ({ browser }) => {
    const ctx  = await browser.newContext({ storageState: 'tests/e2e/.auth/user.json' });
    const page = await ctx.newPage();

    await page.goto('http://127.0.0.1:8000/productos');
    await page.getByPlaceholder('Nombre, código de barras o descripción...').fill(PRODUCTO_NOMBRE);
    await page.waitForLoadState('networkidle');

    const fila = page.locator('tbody tr').filter({ hasText: PRODUCTO_NOMBRE });
    if (await fila.isVisible()) {
      page.on('dialog', d => d.accept());
      await fila.locator('button').nth(2).click();
    }

    await ctx.close();
  });

  test.beforeEach(async ({ page }) => {
    await page.goto('/inventario');
    await expect(page.getByRole('heading', { name: 'Gestión de Inventario' })).toBeVisible();
  });

  // ─── CREAR ───────────────────────────────────────────────────────────────

  test('crear entrada de inventario para un producto', async ({ page }) => {
    await page.getByRole('button', { name: 'Agregar Inventario' }).click();

    const modal = page.locator('[role="dialog"][aria-modal="true"]');
    await expect(modal.getByRole('heading', { name: 'Nuevo Inventario' })).toBeVisible();

    // Admin es root → 2 selects: sucursal y producto
    const selects = modal.locator('select');

    // Seleccionar primera sucursal
    const branchSelect = selects.nth(0);
    await branchSelect.selectOption({ index: 1 });

    // Seleccionar el producto creado en beforeAll (busca el option por texto parcial)
    const productSelect = selects.nth(1);
    const productOption = productSelect.locator(`option:has-text("${PRODUCTO_NOMBRE}")`);
    await expect(productOption).toBeAttached({ timeout: 5_000 });
    const productValue = await productOption.getAttribute('value');
    await productSelect.selectOption(productValue!);

    // Cantidad = 50, min_stock = 5
    const inputs = modal.locator('input[type="number"]');
    await inputs.nth(0).clear();
    await inputs.nth(0).fill('50');
    await inputs.nth(1).clear();
    await inputs.nth(1).fill('5');

    await modal.getByRole('button', { name: 'Guardar' }).click();
    await expect(modal).not.toBeVisible({ timeout: 8_000 });

    await expect(page.locator('tbody tr').filter({ hasText: PRODUCTO_NOMBRE })).toBeVisible({ timeout: 5_000 });
  });

  // ─── BUSCAR ───────────────────────────────────────────────────────────────

  test('buscar producto en inventario', async ({ page }) => {
    await page.getByPlaceholder('Nombre o código de producto...').fill(PRODUCTO_NOMBRE);
    await page.waitForLoadState('networkidle');

    await expect(page.locator('tbody tr').filter({ hasText: PRODUCTO_NOMBRE })).toBeVisible();
  });

  // ─── AJUSTAR STOCK (ENTRADA) ──────────────────────────────────────────────

  test('ajustar stock — entrada de 10 unidades', async ({ page }) => {
    await page.getByPlaceholder('Nombre o código de producto...').fill(PRODUCTO_NOMBRE);
    await page.waitForLoadState('networkidle');

    const fila = page.locator('tbody tr').filter({ hasText: PRODUCTO_NOMBRE });
    await expect(fila).toBeVisible({ timeout: 5_000 });
    await fila.locator('button').nth(0).click();

    const modal = page.locator('.fixed.inset-0.z-50').filter({ hasText: 'Ajustar Stock' });
    await expect(modal.getByRole('heading', { name: 'Ajustar Stock' })).toBeVisible();
    await expect(modal.getByRole('button', { name: /entrada/i })).toHaveClass(/bg-green-500/);

    await modal.locator('input[type="number"]').fill('10');
    await modal.getByRole('button', { name: 'Confirmar' }).click();
    await expect(modal).not.toBeVisible({ timeout: 8_000 });

    // Stock: 50 + 10 = 60
    await page.waitForLoadState('networkidle');
    await expect(page.locator('tbody tr').filter({ hasText: PRODUCTO_NOMBRE }).getByText('60')).toBeVisible();
  });

  // ─── AJUSTAR STOCK (SALIDA) ────────────────────────────────────────────────

  test('ajustar stock — salida de 5 unidades', async ({ page }) => {
    await page.getByPlaceholder('Nombre o código de producto...').fill(PRODUCTO_NOMBRE);
    await page.waitForLoadState('networkidle');

    const fila = page.locator('tbody tr').filter({ hasText: PRODUCTO_NOMBRE });
    await fila.locator('button').nth(0).click();

    const modal = page.locator('.fixed.inset-0.z-50').filter({ hasText: 'Ajustar Stock' });
    await expect(modal.getByRole('heading', { name: 'Ajustar Stock' })).toBeVisible();

    await modal.getByRole('button', { name: /salida/i }).click();
    await expect(modal.getByRole('button', { name: /salida/i })).toHaveClass(/bg-red-500/);

    await modal.locator('input[type="number"]').fill('5');
    await modal.getByRole('button', { name: 'Confirmar' }).click();
    await expect(modal).not.toBeVisible({ timeout: 8_000 });

    // Stock: 60 - 5 = 55
    await page.waitForLoadState('networkidle');
    await expect(page.locator('tbody tr').filter({ hasText: PRODUCTO_NOMBRE }).getByText('55')).toBeVisible();
  });

  // ─── EDITAR ───────────────────────────────────────────────────────────────

  test('editar stock mínimo', async ({ page }) => {
    await page.getByPlaceholder('Nombre o código de producto...').fill(PRODUCTO_NOMBRE);
    await page.waitForLoadState('networkidle');

    const fila = page.locator('tbody tr').filter({ hasText: PRODUCTO_NOMBRE });
    await fila.locator('button').nth(1).click();

    const modal = page.locator('.fixed.inset-0.z-50').filter({ hasText: 'Editar Inventario' });
    await expect(modal.getByRole('heading', { name: 'Editar Inventario' })).toBeVisible();

    const minStockInput = modal.locator('input[type="number"]').nth(1);
    await minStockInput.clear();
    await minStockInput.fill('10');

    await modal.getByRole('button', { name: 'Guardar' }).click();
    await expect(modal).not.toBeVisible({ timeout: 8_000 });

    await page.waitForLoadState('networkidle');
    await expect(page.locator('tbody tr').filter({ hasText: PRODUCTO_NOMBRE }).getByText('10')).toBeVisible();
  });

  // ─── ELIMINAR ─────────────────────────────────────────────────────────────

  test('eliminar inventario', async ({ page }) => {
    await page.getByPlaceholder('Nombre o código de producto...').fill(PRODUCTO_NOMBRE);
    await page.waitForLoadState('networkidle');

    const fila = page.locator('tbody tr').filter({ hasText: PRODUCTO_NOMBRE });
    await expect(fila).toBeVisible({ timeout: 5_000 });

    page.on('dialog', dialog => dialog.accept());
    await fila.locator('button').nth(2).click();

    await expect(fila).not.toBeVisible({ timeout: 8_000 });
  });

});
