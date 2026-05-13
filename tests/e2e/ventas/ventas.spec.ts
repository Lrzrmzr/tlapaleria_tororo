import { test, expect } from '@playwright/test';

const ts             = Date.now();
const PRODUCTO_NOMBRE = `Venta Test ${ts}`;

// ─── SETUP / TEARDOWN ────────────────────────────────────────────────────────

test.beforeAll(async ({ browser }) => {
  const ctx  = await browser.newContext({ storageState: 'tests/e2e/.auth/user.json' });
  const page = await ctx.newPage();

  // 1. Crear producto
  await page.goto('http://127.0.0.1:8000/productos');
  await page.getByRole('button', { name: 'Nuevo Producto' }).click();
  const prodModal = page.locator('[role="dialog"][aria-modal="true"]');
  await prodModal.locator('input[type="text"]').nth(0).fill(PRODUCTO_NOMBRE);
  await prodModal.locator('input[type="number"]').first().fill('150');
  await prodModal.getByRole('button', { name: 'Guardar Producto' }).click();
  await expect(prodModal).not.toBeVisible({ timeout: 8_000 });

  // 2. Crear inventario para ese producto (con 100 unidades)
  await page.goto('http://127.0.0.1:8000/inventario');
  await page.getByRole('button', { name: 'Agregar Inventario' }).click();
  const invModal = page.locator('[role="dialog"][aria-modal="true"]');
  const selects  = invModal.locator('select');
  await selects.nth(0).selectOption({ index: 1 });            // primera sucursal
  const productOption = selects.nth(1).locator(`option:has-text("${PRODUCTO_NOMBRE}")`);
  await expect(productOption).toBeAttached({ timeout: 5_000 });
  const val = await productOption.getAttribute('value');
  await selects.nth(1).selectOption(val!);
  await invModal.locator('input[type="number"]').nth(0).fill('100');
  await invModal.locator('input[type="number"]').nth(1).fill('5');
  await invModal.getByRole('button', { name: 'Guardar' }).click();
  await expect(invModal).not.toBeVisible({ timeout: 8_000 });

  await ctx.close();
});

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

// ─── TESTS ────────────────────────────────────────────────────────────────────

test.describe('Ventas', () => {

  test.beforeEach(async ({ page }) => {
    await page.goto('/ventas');
    await expect(page.getByRole('heading', { name: 'Gestión de Ventas' })).toBeVisible({ timeout: 10_000 });
  });

  // ─── VENTA LIBRE ──────────────────────────────────────────────────────────

  test('registrar venta libre', async ({ page }) => {
    // El botón solo aparece si ventasLibresHabilitadas = true
    const btnLibre = page.getByRole('button', { name: 'Nueva Venta Libre' });
    if (!await btnLibre.isVisible()) {
      test.skip(true, 'Ventas libres no habilitadas en settings');
      return;
    }

    await btnLibre.click();
    const modal = page.locator('[role="dialog"][aria-modal="true"]');
    await expect(modal.getByRole('heading', { name: 'Nueva Venta Libre' })).toBeVisible();

    await modal.locator('textarea').fill('Clavos y tornillos - test E2E');
    await modal.locator('input[type="number"]').fill('250.00');

    await modal.getByRole('button', { name: 'Guardar Venta' }).click();
    await expect(modal).not.toBeVisible({ timeout: 8_000 });

    // La venta aparece en la tabla con badge "Libre"
    const ventaFila = page.locator('tbody tr').filter({ hasText: 'Clavos y tornillos' }).first();
    await expect(ventaFila).toBeVisible({ timeout: 5_000 });
    await expect(ventaFila.locator('span').filter({ hasText: 'Libre' })).toBeVisible();
  });

  // ─── VENTA DE CATÁLOGO ────────────────────────────────────────────────────

  test('registrar venta de catálogo', async ({ page }) => {
    await page.getByRole('button', { name: 'Venta de Catálogo' }).click();

    const modal = page.locator('[role="dialog"][aria-modal="true"]');
    await expect(modal.getByRole('heading', { name: 'Venta de Catálogo' })).toBeVisible();

    // Buscar el producto creado en beforeAll (nombre completo evita ambigüedad con productos residuales)
    const searchInput = modal.getByPlaceholder('Escribe para buscar por nombre o código de barras...');
    await searchInput.fill(PRODUCTO_NOMBRE);

    // Esperar y hacer clic en la sugerencia
    const sugerencia = modal.locator('button').filter({ hasText: PRODUCTO_NOMBRE });
    await expect(sugerencia).toBeVisible({ timeout: 5_000 });
    await sugerencia.click();

    // El input de búsqueda ahora muestra el nombre del producto seleccionado
    await expect(searchInput).toHaveValue(PRODUCTO_NOMBRE);

    // Establecer cantidad = 2
    const cantidadInput = modal.locator('input[type="number"]');
    await cantidadInput.clear();
    await cantidadInput.fill('2');

    // Agregar al carrito
    await modal.getByRole('button', { name: /agregar al carrito/i }).click();

    // El carrito debe tener 1 item con el producto
    await expect(modal.getByText('Carrito de Compra (1 items)')).toBeVisible({ timeout: 3_000 });

    // Registrar la venta
    await modal.getByRole('button', { name: /completar venta/i }).click();
    await expect(modal).not.toBeVisible({ timeout: 8_000 });

    // La venta aparece en la tabla con badge "Catálogo"
    const ventaFila = page.locator('tbody tr').filter({ hasText: PRODUCTO_NOMBRE }).first();
    await expect(ventaFila).toBeVisible({ timeout: 5_000 });
    await expect(ventaFila.locator('span').filter({ hasText: 'Catálogo' })).toBeVisible();
  });

  // ─── VER DETALLES ─────────────────────────────────────────────────────────

  test('ver detalles de una venta', async ({ page }) => {
    // Verificar que haya al menos una venta en la tabla
    const primeraFila = page.locator('tbody tr').first();
    await expect(primeraFila).toBeVisible({ timeout: 5_000 });
    await primeraFila.getByRole('button', { name: 'Ver Detalles' }).click();

    const modal = page.locator('.fixed.inset-0.z-50').filter({ hasText: 'Detalles' });
    await expect(modal).toBeVisible({ timeout: 3_000 });

    // El modal de detalles muestra información de la venta
    await expect(modal.getByText('Total de la Venta')).toBeVisible();
  });

  // ─── VALIDACIÓN: CARRITO VACÍO ────────────────────────────────────────────

  test('no se puede registrar venta de catálogo con carrito vacío', async ({ page }) => {
    await page.getByRole('button', { name: 'Venta de Catálogo' }).click();

    const modal = page.locator('[role="dialog"][aria-modal="true"]');
    await expect(modal.getByRole('heading', { name: 'Venta de Catálogo' })).toBeVisible();

    // El botón "Completar Venta" está deshabilitado cuando el carrito está vacío
    const btnCompletar = modal.getByRole('button', { name: /completar venta/i });
    await expect(btnCompletar).toBeDisabled();

    // El modal permanece abierto
    await expect(modal).toBeVisible();
  });

});
