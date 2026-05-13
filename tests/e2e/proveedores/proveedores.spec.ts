import { test, expect } from '@playwright/test';

// Nombre único por corrida para no chocar con datos existentes
const ts = Date.now();
const NOMBRE    = `Proveedor Test ${ts}`;
const NOMBRE_ED = `Proveedor Editado ${ts}`;
const CONTACTO  = 'Juan Prueba';
const TELEFONO  = '555-0100';
const EMAIL     = `proveedor${ts}@test.com`;

test.describe('Proveedores', () => {

  test.beforeEach(async ({ page }) => {
    await page.goto('/proveedores');
    await expect(page.getByRole('heading', { name: 'Gestión de Proveedores' })).toBeVisible();
  });

  // ─── CREAR ───────────────────────────────────────────────────────────────

  test('crear proveedor nuevo', async ({ page }) => {
    // Abrir modal
    await page.getByRole('button', { name: 'Nuevo Proveedor' }).click();
    const modal = page.locator('.fixed.inset-0.z-50').first();
    await expect(modal.getByRole('heading', { name: 'Nuevo Proveedor' })).toBeVisible();

    // Llenar campos
    await modal.locator('input[type="text"]').nth(0).fill(NOMBRE);       // Empresa
    await modal.locator('input[type="text"]').nth(1).fill(CONTACTO);     // Contacto
    await modal.locator('input[type="text"]').nth(2).fill(TELEFONO);     // Teléfono
    await modal.locator('input[type="email"]').fill(EMAIL);              // Email
    await modal.locator('textarea').fill('Calle Falsa 123');             // Dirección

    // Guardar
    await modal.getByRole('button', { name: 'Guardar' }).click();

    // Modal cierra y el proveedor aparece en la tabla
    await expect(modal).not.toBeVisible({ timeout: 5_000 });
    await expect(page.getByRole('cell', { name: NOMBRE })).toBeVisible();
  });

  // ─── BUSCAR ───────────────────────────────────────────────────────────────

  test('filtrar proveedores por nombre', async ({ page }) => {
    const search = page.getByPlaceholder('Nombre, contacto o email...');
    await search.fill(NOMBRE);

    // Solo debe verse la fila que coincide (o ninguna si aún no existe)
    const filas = page.locator('tbody tr').filter({ hasText: NOMBRE });
    // Con el proveedor creado antes, debe haber exactamente 1
    await expect(filas).toHaveCount(1);
  });

  // ─── EDITAR ───────────────────────────────────────────────────────────────

  test('editar proveedor existente', async ({ page }) => {
    // Buscar la fila del proveedor creado
    const fila = page.locator('tbody tr').filter({ hasText: NOMBRE });
    await expect(fila).toBeVisible({ timeout: 5_000 });

    // Clic en botón editar (ícono lápiz, primer botón amarillo de la fila)
    await fila.locator('button').first().click();

    const modal = page.locator('.fixed.inset-0.z-50').first();
    await expect(modal.getByRole('heading', { name: 'Editar Proveedor' })).toBeVisible();

    // Cambiar nombre
    const inputNombre = modal.locator('input[type="text"]').nth(0);
    await inputNombre.clear();
    await inputNombre.fill(NOMBRE_ED);

    await modal.getByRole('button', { name: 'Actualizar' }).click();

    // Modal cierra y aparece el nombre editado
    await expect(modal).not.toBeVisible({ timeout: 5_000 });
    await expect(page.getByRole('cell', { name: NOMBRE_ED })).toBeVisible();
  });

  // ─── ELIMINAR ─────────────────────────────────────────────────────────────

  test('eliminar proveedor', async ({ page }) => {
    const fila = page.locator('tbody tr').filter({ hasText: NOMBRE_ED });
    await expect(fila).toBeVisible({ timeout: 5_000 });

    // Confirmar el dialog nativo del navegador automáticamente
    page.on('dialog', dialog => dialog.accept());

    // Clic en botón eliminar (ícono basurero, segundo botón rojo de la fila)
    await fila.locator('button').nth(1).click();

    // La fila desaparece de la tabla
    await expect(fila).not.toBeVisible({ timeout: 5_000 });
  });

  // ─── CANCELAR MODAL ──────────────────────────────────────────────────────

  test('cancelar modal no guarda cambios', async ({ page }) => {
    await page.getByRole('button', { name: 'Nuevo Proveedor' }).click();
    const modal = page.locator('.fixed.inset-0.z-50').first();
    await modal.locator('input[type="text"]').nth(0).fill('NO GUARDAR ESTO');
    await modal.getByRole('button', { name: 'Cancelar' }).click();

    await expect(modal).not.toBeVisible({ timeout: 3_000 });
    await expect(page.getByRole('cell', { name: 'NO GUARDAR ESTO' })).not.toBeVisible();
  });

});
