import { test, expect } from '@playwright/test';

const ts        = Date.now();
const NOMBRE    = `Categoría Test ${ts}`;
const NOMBRE_ED = `Categoría Editada ${ts}`;

test.describe('Categorías', () => {

  test.beforeEach(async ({ page }) => {
    await page.goto('/categorias');
    await expect(page.getByRole('heading', { name: 'Categorías' })).toBeVisible({ timeout: 10_000 });
  });

  // ─── CREAR ────────────────────────────────────────────────────────────────

  test('crear nueva categoría', async ({ page }) => {
    await page.getByRole('button', { name: '+ Nueva Categoría' }).click();

    const modal = page.locator('.fixed.inset-0').filter({ hasText: 'Nueva Categoría' });
    await expect(modal.getByRole('heading', { name: 'Nueva Categoría' })).toBeVisible();

    await modal.locator('input[type="text"]').first().fill(NOMBRE);
    await modal.locator('textarea').fill('Descripción de prueba E2E');

    await modal.getByRole('button', { name: 'Crear' }).click();
    await expect(modal).not.toBeVisible({ timeout: 8_000 });

    // La categoría aparece en el grid de cards
    await expect(page.getByText(NOMBRE)).toBeVisible({ timeout: 5_000 });
  });

  // ─── BUSCAR ────────────────────────────────────────────────────────────────

  test('filtrar categorías por nombre', async ({ page }) => {
    await page.getByPlaceholder('Buscar categorías...').fill(NOMBRE);

    await expect(page.getByText(NOMBRE)).toBeVisible({ timeout: 3_000 });
  });

  // ─── EDITAR ────────────────────────────────────────────────────────────────

  test('editar categoría existente', async ({ page }) => {
    // Buscar la card de la categoría y hacer clic en el botón editar (✏️)
    const card = page.locator('.bg-white, .dark\\:bg-gray-800').filter({ hasText: NOMBRE }).first();
    await expect(card).toBeVisible({ timeout: 5_000 });
    await card.getByTitle('Editar').click();

    const modal = page.locator('.fixed.inset-0').filter({ hasText: 'Editar Categoría' });
    await expect(modal.getByRole('heading', { name: 'Editar Categoría' })).toBeVisible();

    const inputNombre = modal.locator('input[type="text"]').first();
    await inputNombre.clear();
    await inputNombre.fill(NOMBRE_ED);

    await modal.getByRole('button', { name: 'Actualizar' }).click();
    await expect(modal).not.toBeVisible({ timeout: 8_000 });

    await expect(page.getByText(NOMBRE_ED)).toBeVisible({ timeout: 5_000 });
  });

  // ─── CANCELAR MODAL ───────────────────────────────────────────────────────

  test('cancelar modal no guarda cambios', async ({ page }) => {
    await page.getByRole('button', { name: '+ Nueva Categoría' }).click();
    const modal = page.locator('.fixed.inset-0').filter({ hasText: 'Nueva Categoría' });
    await modal.locator('input[type="text"]').first().fill('NO GUARDAR ESTO');
    await modal.getByRole('button', { name: 'Cancelar' }).click();

    await expect(modal).not.toBeVisible({ timeout: 3_000 });
    await expect(page.getByText('NO GUARDAR ESTO')).not.toBeVisible();
  });

  // ─── ELIMINAR ─────────────────────────────────────────────────────────────

  test('eliminar categoría sin productos', async ({ page }) => {
    const card = page.locator('.bg-white, .dark\\:bg-gray-800').filter({ hasText: NOMBRE_ED }).first();
    await expect(card).toBeVisible({ timeout: 5_000 });

    page.on('dialog', d => d.accept());
    await card.getByTitle('Eliminar').click();

    await expect(page.getByText(NOMBRE_ED)).not.toBeVisible({ timeout: 8_000 });
  });

});
