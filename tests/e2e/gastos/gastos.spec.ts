import { test, expect } from '@playwright/test';

const ts   = Date.now();
const DESC = `Gasto Test ${ts}`;

test.describe.serial('Gastos', () => {

  test.beforeEach(async ({ page }) => {
    await page.goto('/gastos');
    await expect(page.getByRole('heading', { name: 'Gastos', exact: true })).toBeVisible({ timeout: 10_000 });
  });

  // ─── CREAR ────────────────────────────────────────────────────────────────

  test('registrar nuevo gasto', async ({ page }) => {
    await page.getByRole('button', { name: 'Agregar Gasto' }).click();

    // El título "Nuevo Gasto" está fuera del <form>, como hermano dentro del mismo contenedor
    await expect(page.getByRole('heading', { name: 'Nuevo Gasto' })).toBeVisible();

    const form = page.locator('form');
    await form.getByPlaceholder('Ej: Compra de material de oficina').fill(DESC);
    await form.locator('input[type="number"]').fill('350.00');

    // root no tiene sucursal propia: el formulario exige elegir una explícitamente.
    const branchSelect = form.locator('select');
    if (await branchSelect.isVisible().catch(() => false)) {
      await branchSelect.selectOption({ index: 1 });
    }

    await form.getByRole('button', { name: 'Guardar Gasto' }).click();

    // El formulario se oculta y la descripción aparece en la tabla
    await expect(page.getByRole('heading', { name: 'Nuevo Gasto' })).not.toBeVisible({ timeout: 8_000 });
    await expect(page.locator('tbody tr').filter({ hasText: DESC })).toBeVisible({ timeout: 5_000 });
  });

  // ─── TOTAL EN TABLA ────────────────────────────────────────────────────────

  test('el gasto registrado muestra el monto correcto', async ({ page }) => {
    const fila = page.locator('tbody tr').filter({ hasText: DESC });
    await expect(fila).toBeVisible({ timeout: 5_000 });
    // $350.00 MXN
    await expect(fila.getByText(/350/)).toBeVisible();
  });

  // ─── CANCELAR FORMULARIO ──────────────────────────────────────────────────

  test('cancelar formulario no crea gasto', async ({ page }) => {
    await page.getByRole('button', { name: 'Agregar Gasto' }).click();
    await expect(page.getByRole('heading', { name: 'Nuevo Gasto' })).toBeVisible();

    const form = page.locator('form');
    await form.getByPlaceholder('Ej: Compra de material de oficina').fill('NO GUARDAR ESTO');
    await form.getByRole('button', { name: 'Cancelar' }).click();

    await expect(page.getByRole('heading', { name: 'Nuevo Gasto' })).not.toBeVisible({ timeout: 3_000 });
    await expect(page.locator('tbody').getByText('NO GUARDAR ESTO')).not.toBeVisible();
  });

  // ─── ELIMINAR ─────────────────────────────────────────────────────────────

  test('eliminar gasto registrado', async ({ page }) => {
    const fila = page.locator('tbody tr').filter({ hasText: DESC });
    await expect(fila).toBeVisible({ timeout: 5_000 });

    page.on('dialog', d => d.accept());
    await fila.getByRole('button', { name: /eliminar/i }).click();

    await expect(fila).not.toBeVisible({ timeout: 8_000 });
  });

  // ─── ESTADÍSTICAS ─────────────────────────────────────────────────────────

  test('muestra estadísticas de gastos', async ({ page }) => {
    await expect(page.getByText('Total de Gastos')).toBeVisible();
    await expect(page.getByText('Total de Registros')).toBeVisible();
  });

});
