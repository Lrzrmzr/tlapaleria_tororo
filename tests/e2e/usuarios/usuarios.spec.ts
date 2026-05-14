import { test, expect } from '@playwright/test';

test.describe('Usuarios', () => {

  test.beforeEach(async ({ page }) => {
    await page.goto('/usuarios');
    await expect(page.getByRole('heading', { name: 'Gestión de Usuarios' })).toBeVisible({ timeout: 10_000 });
  });

  // ─── STATS ────────────────────────────────────────────────────────────────

  test('muestra tarjetas de estadísticas', async ({ page }) => {
    await expect(page.getByText('Total Usuarios')).toBeVisible();
    await expect(page.getByText('Con Rol Asignado')).toBeVisible();
    await expect(page.getByText('Sin Rol', { exact: true })).toBeVisible();
    await expect(page.getByText('Verificados')).toBeVisible();
  });

  // ─── TABLA ────────────────────────────────────────────────────────────────

  test('la tabla lista al menos un usuario', async ({ page }) => {
    const primeraFila = page.locator('tbody tr').first();
    await expect(primeraFila).toBeVisible({ timeout: 5_000 });
  });

  // ─── VER DETALLES ─────────────────────────────────────────────────────────

  test('ver detalles de un usuario', async ({ page }) => {
    const primeraFila = page.locator('tbody tr').first();
    await expect(primeraFila).toBeVisible({ timeout: 5_000 });

    await primeraFila.getByRole('button', { name: 'Ver Detalles' }).click();

    const modal = page.locator('[role="dialog"][aria-modal="true"]');
    await expect(modal.getByRole('heading', { name: 'Detalles de Usuario' })).toBeVisible({ timeout: 5_000 });

    // El modal muestra campos clave
    await expect(modal.getByText('Nombre')).toBeVisible();
    await expect(modal.getByText('Email', { exact: true })).toBeVisible();
    await expect(modal.getByText('Rol')).toBeVisible();

    await modal.getByRole('button', { name: 'Cerrar' }).click();
    await expect(modal).not.toBeVisible({ timeout: 5_000 });
  });

  // ─── FILTRO POR ROL ───────────────────────────────────────────────────────

  test('filtrar usuarios sin rol asignado', async ({ page }) => {
    const selectRol = page.locator('select').first();
    await selectRol.selectOption('sin_rol');

    // El contador cambia
    await expect(page.getByText(/Mostrando \d+ de \d+ usuarios/)).toBeVisible();
  });

  // ─── ASIGNAR ROL (root puede asignar) ─────────────────────────────────────

  test('el admin puede ver los selects de rol y sucursal', async ({ page }) => {
    // El usuario admin (root) debe ver los selects de asignación
    const primeraFila = page.locator('tbody tr').first();
    await expect(primeraFila).toBeVisible({ timeout: 5_000 });

    // Debe existir al menos un select de rol en la tabla
    const selectsEnTabla = page.locator('tbody select');
    await expect(selectsEnTabla.first()).toBeVisible();
  });

});
