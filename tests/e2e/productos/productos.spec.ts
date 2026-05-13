import { test, expect } from '@playwright/test';

const ts       = Date.now();
const NOMBRE   = `Producto Test ${ts}`;
const NOMBRE_ED = `Producto Editado ${ts}`;
const PRECIO   = '125.50';
const PRECIO_ED = '199.00';
const BARCODE  = `TEST${ts}`;

test.describe('Productos', () => {

  test.beforeEach(async ({ page }) => {
    await page.goto('/productos');
    await expect(page.getByRole('heading', { name: 'Catálogo de Productos' })).toBeVisible();
  });

  // ─── CREAR ───────────────────────────────────────────────────────────────

  test('crear producto nuevo', async ({ page }) => {
    await page.getByRole('button', { name: 'Nuevo Producto' }).click();

    // El modal tiene role="dialog" aria-modal="true"
    const modal = page.locator('[role="dialog"][aria-modal="true"]');
    await expect(modal.getByRole('heading', { name: 'Nuevo Producto' })).toBeVisible();

    // Nombre (primer input type text en el modal)
    await modal.locator('input[type="text"]').nth(0).fill(NOMBRE);
    // Descripción
    await modal.locator('textarea').fill('Producto generado por test E2E');
    // Código de barras (segundo input type text)
    await modal.locator('input[type="text"]').nth(1).fill(BARCODE);
    // Precio de venta
    await modal.locator('input[type="number"]').first().fill(PRECIO);

    await modal.getByRole('button', { name: 'Guardar Producto' }).click();

    // Modal cierra y el producto aparece en la tabla
    await expect(modal).not.toBeVisible({ timeout: 8_000 });
    await expect(page.getByRole('cell', { name: NOMBRE })).toBeVisible();
  });

  // ─── BUSCAR ───────────────────────────────────────────────────────────────

  test('buscar producto por nombre', async ({ page }) => {
    const search = page.getByPlaceholder('Nombre, código de barras o descripción...');
    await search.fill(NOMBRE);

    // Esperar que Inertia haga la petición server-side (debounce 300ms + red)
    await page.waitForLoadState('networkidle');

    const fila = page.locator('tbody tr').filter({ hasText: NOMBRE });
    await expect(fila).toBeVisible({ timeout: 5_000 });
    await expect(fila.getByText(BARCODE)).toBeVisible();
  });

  test('buscar producto por código de barras', async ({ page }) => {
    const search = page.getByPlaceholder('Nombre, código de barras o descripción...');
    await search.fill(BARCODE);

    await page.waitForLoadState('networkidle');

    await expect(page.locator('tbody tr').filter({ hasText: BARCODE })).toBeVisible();
  });

  // ─── EDITAR ───────────────────────────────────────────────────────────────

  test('editar producto existente', async ({ page }) => {
    // Buscar primero para localizar la fila
    await page.getByPlaceholder('Nombre, código de barras o descripción...').fill(NOMBRE);
    await page.waitForLoadState('networkidle');

    const fila = page.locator('tbody tr').filter({ hasText: NOMBRE });
    await expect(fila).toBeVisible({ timeout: 5_000 });

    // Botones por fila: [0] proveedores, [1] editar (amarillo), [2] eliminar (rojo)
    await fila.locator('button').nth(1).click();

    // El modal de editar no tiene aria-modal — busco por el heading dentro del overlay
    const modal = page.locator('.fixed.inset-0.z-50').filter({ hasText: 'Editar Producto' });
    await expect(modal.getByRole('heading', { name: 'Editar Producto' })).toBeVisible();

    // Cambiar nombre y precio
    const inputNombre = modal.locator('input[type="text"]').nth(0);
    await inputNombre.clear();
    await inputNombre.fill(NOMBRE_ED);

    const inputPrecio = modal.locator('input[type="number"]').first();
    await inputPrecio.clear();
    await inputPrecio.fill(PRECIO_ED);

    await modal.getByRole('button', { name: 'Actualizar' }).click();

    await expect(modal).not.toBeVisible({ timeout: 8_000 });
    await expect(page.getByRole('cell', { name: NOMBRE_ED })).toBeVisible();
  });

  // ─── PRECIO VISIBLE EN TABLA ──────────────────────────────────────────────

  test('el precio editado se muestra correctamente en la tabla', async ({ page }) => {
    await page.getByPlaceholder('Nombre, código de barras o descripción...').fill(NOMBRE_ED);
    await page.waitForLoadState('networkidle');

    const fila = page.locator('tbody tr').filter({ hasText: NOMBRE_ED });
    await expect(fila).toBeVisible();

    // El precio formateado en MXN aparece en la fila
    // 199.00 → $199.00 en es-MX
    await expect(fila.getByText(/199/)).toBeVisible();
  });

  // ─── ELIMINAR ─────────────────────────────────────────────────────────────

  test('eliminar producto', async ({ page }) => {
    await page.getByPlaceholder('Nombre, código de barras o descripción...').fill(NOMBRE_ED);
    await page.waitForLoadState('networkidle');

    const fila = page.locator('tbody tr').filter({ hasText: NOMBRE_ED });
    await expect(fila).toBeVisible({ timeout: 5_000 });

    page.on('dialog', dialog => dialog.accept());
    // Botones por fila: [0] proveedores, [1] editar, [2] eliminar (rojo)
    await fila.locator('button').nth(2).click();

    await expect(fila).not.toBeVisible({ timeout: 8_000 });
  });

  // ─── VALIDACIÓN ───────────────────────────────────────────────────────────

  test('no se puede crear producto sin nombre', async ({ page }) => {
    await page.getByRole('button', { name: 'Nuevo Producto' }).click();

    const modal = page.locator('[role="dialog"][aria-modal="true"]');
    // Precio pero sin nombre
    await modal.locator('input[type="number"]').first().fill('50');
    await modal.getByRole('button', { name: 'Guardar Producto' }).click();

    // HTML5 required — modal permanece abierto
    await expect(modal).toBeVisible();
    await expect(page).toHaveURL('/productos');
  });

});
