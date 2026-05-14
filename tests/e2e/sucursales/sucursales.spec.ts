import { test, expect } from '@playwright/test';

const ts        = Date.now();
const NOMBRE    = `Sucursal Test ${ts}`;
const CODIGO    = `T${ts}`;   // timestamp completo → único por ejecución (max 14 chars < 20)
const NOMBRE_ED = `Sucursal Editada ${ts}`;

test.describe.serial('Sucursales', () => {

  test.beforeEach(async ({ page }) => {
    await page.goto('/sucursales');
    await expect(page.getByRole('heading', { name: 'Gestión de Sucursales' })).toBeVisible({ timeout: 10_000 });
  });

  // ─── CREAR ────────────────────────────────────────────────────────────────

  test('crear nueva sucursal', async ({ page }) => {
    await page.getByRole('button', { name: 'Nueva Sucursal' }).click();

    const modal = page.locator('.fixed.inset-0.z-50');
    await expect(modal.getByRole('heading', { name: 'Nueva Sucursal' })).toBeVisible();

    // Nombre (col-span-2, primer input)
    await modal.locator('input[type="text"]').nth(0).fill(NOMBRE);
    // Código
    await modal.locator('input[type="text"]').nth(1).fill(CODIGO);
    // Teléfono
    await modal.locator('input[type="text"]').nth(2).fill('555-9999');
    // Encargado
    await modal.locator('input[type="text"]').nth(3).fill('Encargado Test');

    await modal.getByRole('button', { name: 'Guardar' }).click();
    await expect(modal).not.toBeVisible({ timeout: 8_000 });

    // La sucursal aparece en el grid de cards
    await expect(page.getByText(NOMBRE)).toBeVisible({ timeout: 5_000 });
  });

  // ─── BUSCAR ────────────────────────────────────────────────────────────────

  test('filtrar sucursales por nombre', async ({ page }) => {
    await page.getByPlaceholder('Nombre, código o encargado...').fill(NOMBRE);

    await expect(page.getByText(NOMBRE)).toBeVisible({ timeout: 3_000 });
  });

  // ─── EDITAR ────────────────────────────────────────────────────────────────

  test('editar sucursal existente', async ({ page }) => {
    const card = page.locator('.bg-white, .rounded-2xl').filter({ hasText: NOMBRE }).first();
    await expect(card).toBeVisible({ timeout: 5_000 });
    await card.getByRole('button', { name: 'Editar' }).click();

    const modal = page.locator('.fixed.inset-0.z-50');
    await expect(modal.getByRole('heading', { name: 'Editar Sucursal' })).toBeVisible();

    const inputNombre = modal.locator('input[type="text"]').nth(0);
    await inputNombre.clear();
    await inputNombre.fill(NOMBRE_ED);

    await modal.getByRole('button', { name: 'Actualizar' }).click();
    await expect(modal).not.toBeVisible({ timeout: 8_000 });

    await expect(page.getByText(NOMBRE_ED)).toBeVisible({ timeout: 5_000 });
  });

  // ─── DESACTIVAR / ACTIVAR ─────────────────────────────────────────────────

  test('desactivar y reactivar sucursal no principal', async ({ page }) => {
    const card = page.locator('.rounded-2xl').filter({ hasText: NOMBRE_ED }).first();
    await expect(card).toBeVisible({ timeout: 5_000 });

    // Desactivar
    await card.getByRole('button', { name: 'Desactivar' }).click();
    await expect(card.getByText('Inactiva')).toBeVisible({ timeout: 5_000 });

    // Volver a activar
    await card.getByRole('button', { name: 'Activar' }).click();
    await expect(card.getByText('Activa', { exact: true })).toBeVisible({ timeout: 5_000 });
  });

  // ─── ELIMINAR ─────────────────────────────────────────────────────────────

  test('eliminar sucursal no principal', async ({ page }) => {
    const card = page.locator('.rounded-2xl').filter({ hasText: NOMBRE_ED }).first();
    await expect(card).toBeVisible({ timeout: 5_000 });

    page.on('dialog', d => d.accept());
    // Botón eliminar (basurero rojo, solo visible si no es principal)
    await card.locator('button').last().click();

    await expect(page.getByText(NOMBRE_ED)).not.toBeVisible({ timeout: 8_000 });
  });

  // ─── CANCELAR MODAL ───────────────────────────────────────────────────────

  test('cancelar modal no crea sucursal', async ({ page }) => {
    await page.getByRole('button', { name: 'Nueva Sucursal' }).click();
    const modal = page.locator('.fixed.inset-0.z-50');
    await modal.locator('input[type="text"]').nth(0).fill('NO GUARDAR ESTO');
    await modal.getByRole('button', { name: 'Cancelar' }).click();

    await expect(modal).not.toBeVisible({ timeout: 3_000 });
    await expect(page.getByText('NO GUARDAR ESTO')).not.toBeVisible();
  });

});
