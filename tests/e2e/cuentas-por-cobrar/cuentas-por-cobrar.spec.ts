import { test, expect } from '@playwright/test';

const ts          = Date.now();
const CLIENTE     = `Cliente Test ${ts}`;
const CONCEPTO    = `CxC Test ${ts}`;
const CONCEPTO_ED = `CxC Editada ${ts}`;

test.describe('Cuentas por Cobrar', () => {

  test.beforeEach(async ({ page }) => {
    await page.goto('/cuentas-por-cobrar');
    await expect(page.getByRole('heading', { name: 'Cuentas por Cobrar' })).toBeVisible({ timeout: 10_000 });
  });

  // ─── ACCESO AL MÓDULO ─────────────────────────────────────────────────────

  test('la página carga correctamente', async ({ page }) => {
    await expect(page.getByText(/Total|Por vencer|Vencidas|Cobradas/i).first()).toBeVisible();
  });

  // ─── CREAR CUENTA ─────────────────────────────────────────────────────────

  test('registrar nueva venta a crédito', async ({ page }) => {
    const btnNueva = page.getByRole('button', { name: 'Nueva venta a crédito' });
    if (!await btnNueva.isVisible()) {
      test.skip(true, 'Usuario sin permiso canCreate en CxC');
      return;
    }

    await btnNueva.click();

    const form = page.locator('form').filter({ hasText: 'Concepto' });
    await expect(form).toBeVisible();

    // Seleccionar modo "cliente nuevo" (radio button)
    const radioNuevo = form.locator('input[type="radio"]').nth(1); // segundo radio = cliente nuevo
    await radioNuevo.click();

    // Nombre del cliente nuevo
    await form.getByPlaceholder('Nombre del cliente *').fill(CLIENTE);

    // Concepto
    await form.getByPlaceholder('Descripción de la venta a crédito').fill(CONCEPTO);

    // Monto
    await form.locator('input[type="number"]').fill('800.00');

    await form.getByRole('button', { name: 'Registrar' }).click();
    await expect(form).not.toBeVisible({ timeout: 8_000 });

    await expect(page.getByText(CONCEPTO)).toBeVisible({ timeout: 5_000 });
  });

  // ─── REGISTRAR COBRO ──────────────────────────────────────────────────────

  test('registrar cobro en cuenta existente', async ({ page }) => {
    const filaCuenta = page.locator('.border-b').filter({ hasText: CONCEPTO }).first();
    await expect(filaCuenta).toBeVisible({ timeout: 5_000 });

    await filaCuenta.getByRole('button', { name: 'Registrar cobro' }).click();

    const formCobro = page.locator('form').filter({ hasText: /Guardar cobro/i });
    await expect(formCobro).toBeVisible();

    await formCobro.locator('input[type="number"]').first().fill('200.00');
    await formCobro.getByRole('button', { name: /guardar/i }).click();

    await expect(formCobro).not.toBeVisible({ timeout: 8_000 });

    // El estado cambia a "parcial"
    await expect(filaCuenta.getByText(/parcial/i)).toBeVisible({ timeout: 5_000 });
  });

  // ─── EDITAR CUENTA ────────────────────────────────────────────────────────

  test('editar cuenta por cobrar', async ({ page }) => {
    const filaCuenta = page.locator('.border-b').filter({ hasText: CONCEPTO }).first();
    await expect(filaCuenta).toBeVisible({ timeout: 5_000 });

    await filaCuenta.getByRole('button', { name: 'Editar' }).click();

    const form = page.locator('form').filter({ hasText: 'Concepto' });
    await expect(form).toBeVisible();

    const inputConcepto = form.getByPlaceholder('Descripción de la venta a crédito');
    await inputConcepto.clear();
    await inputConcepto.fill(CONCEPTO_ED);

    await form.getByRole('button', { name: 'Actualizar' }).click();
    await expect(form).not.toBeVisible({ timeout: 8_000 });

    await expect(page.getByText(CONCEPTO_ED)).toBeVisible({ timeout: 5_000 });
  });

  // ─── BUSCAR ────────────────────────────────────────────────────────────────

  test('buscar cuenta por cliente', async ({ page }) => {
    await page.getByPlaceholder('Buscar cliente...').fill(CLIENTE);
    await page.waitForTimeout(400); // debounce del filtro

    await expect(page.getByText(CONCEPTO_ED)).toBeVisible({ timeout: 5_000 });
  });

  // ─── ELIMINAR CUENTA ──────────────────────────────────────────────────────

  test('eliminar cuenta por cobrar', async ({ page }) => {
    const filaCuenta = page.locator('.border-b').filter({ hasText: CONCEPTO_ED }).first();
    await expect(filaCuenta).toBeVisible({ timeout: 5_000 });

    page.on('dialog', d => d.accept());
    await filaCuenta.getByRole('button', { name: 'Eliminar' }).click();

    await expect(page.getByText(CONCEPTO_ED)).not.toBeVisible({ timeout: 8_000 });
  });

});
