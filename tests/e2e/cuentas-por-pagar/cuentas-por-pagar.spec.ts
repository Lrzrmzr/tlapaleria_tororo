import { test, expect } from '@playwright/test';

const ts      = Date.now();
const CONCEPTO     = `CxP Test ${ts}`;
const CONCEPTO_ED  = `CxP Editada ${ts}`;

test.describe('Cuentas por Pagar', () => {

  test.beforeEach(async ({ page }) => {
    await page.goto('/cuentas-por-pagar');
    await expect(page.getByRole('heading', { name: 'Cuentas por Pagar' })).toBeVisible({ timeout: 10_000 });
  });

  // ─── ACCESO AL MÓDULO ─────────────────────────────────────────────────────

  test('la página carga correctamente', async ({ page }) => {
    // Estadísticas siempre visibles
    await expect(page.getByText(/Total|Por vencer|Vencidas|Pagadas/i).first()).toBeVisible();
  });

  // ─── CREAR CUENTA ─────────────────────────────────────────────────────────

  test('registrar nueva cuenta por pagar', async ({ page }) => {
    const btnNueva = page.getByRole('button', { name: 'Nueva nota' });
    if (!await btnNueva.isVisible()) {
      test.skip(true, 'Usuario sin permiso canCreate en CxP');
      return;
    }

    await btnNueva.click();

    const form = page.locator('form').filter({ hasText: 'Proveedor' });
    await expect(form).toBeVisible();

    // Seleccionar primer proveedor disponible
    const selectProveedor = form.locator('select').first();
    await selectProveedor.selectOption({ index: 1 });

    // Concepto
    await form.getByPlaceholder('Descripción de la deuda con el proveedor').fill(CONCEPTO);

    // Monto
    await form.locator('input[type="number"]').fill('1500.00');

    await form.getByRole('button', { name: 'Registrar' }).click();
    await expect(form).not.toBeVisible({ timeout: 8_000 });

    await expect(page.getByText(CONCEPTO)).toBeVisible({ timeout: 5_000 });
  });

  // ─── REGISTRAR PAGO ───────────────────────────────────────────────────────

  test('registrar pago en cuenta existente', async ({ page }) => {
    const filaCuenta = page.locator('.border-b').filter({ hasText: CONCEPTO }).first();
    await expect(filaCuenta).toBeVisible({ timeout: 5_000 });

    await filaCuenta.getByRole('button', { name: 'Registrar pago' }).click();

    const formPago = page.locator('form').filter({ hasText: /Guardar pago/i });
    await expect(formPago).toBeVisible();

    await formPago.locator('input[type="number"]').first().fill('500.00');
    await formPago.getByRole('button', { name: 'Guardar pago' }).click();

    await expect(formPago).not.toBeVisible({ timeout: 8_000 });

    // El estado cambia a "parcial"
    await expect(filaCuenta.getByText(/parcial/i)).toBeVisible({ timeout: 5_000 });
  });

  // ─── EDITAR CUENTA ────────────────────────────────────────────────────────

  test('editar cuenta por pagar', async ({ page }) => {
    const filaCuenta = page.locator('.border-b').filter({ hasText: CONCEPTO }).first();
    await expect(filaCuenta).toBeVisible({ timeout: 5_000 });

    await filaCuenta.getByRole('button', { name: 'Editar' }).click();

    const form = page.locator('form').filter({ hasText: 'Proveedor' });
    await expect(form).toBeVisible();

    const inputConcepto = form.getByPlaceholder('Descripción de la deuda con el proveedor');
    await inputConcepto.clear();
    await inputConcepto.fill(CONCEPTO_ED);

    await form.getByRole('button', { name: 'Actualizar' }).click();
    await expect(form).not.toBeVisible({ timeout: 8_000 });

    await expect(page.getByText(CONCEPTO_ED)).toBeVisible({ timeout: 5_000 });
  });

  // ─── ELIMINAR CUENTA ──────────────────────────────────────────────────────

  test('eliminar cuenta por pagar', async ({ page }) => {
    const filaCuenta = page.locator('.border-b').filter({ hasText: CONCEPTO_ED }).first();
    await expect(filaCuenta).toBeVisible({ timeout: 5_000 });

    page.on('dialog', d => d.accept());
    await filaCuenta.getByRole('button', { name: 'Eliminar' }).click();

    await expect(page.getByText(CONCEPTO_ED)).not.toBeVisible({ timeout: 8_000 });
  });

  // ─── FILTROS ──────────────────────────────────────────────────────────────

  test('filtrar por estado pendiente', async ({ page }) => {
    const selectEstado = page.locator('select').filter({ hasText: /estado|pendiente/i }).first();
    await selectEstado.selectOption('pendiente');

    await page.waitForLoadState('networkidle');
    // Todos los resultados deben tener badge pendiente o la tabla vacía
    await expect(page.locator('body')).not.toContainText('pagada');
  });

});
