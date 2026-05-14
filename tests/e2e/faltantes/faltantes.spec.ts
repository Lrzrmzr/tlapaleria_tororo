import { test, expect } from '@playwright/test';

const ts   = Date.now();
const DESC = `Faltante Test ${ts}`;
const DESC_EDIT = `Faltante Editado ${ts}`;

test.describe('Faltantes', () => {

  test.beforeEach(async ({ page }) => {
    await page.goto('/faltantes');
    await expect(page.getByRole('heading', { name: 'Gestión de Faltantes' })).toBeVisible({ timeout: 10_000 });
  });

  // ─── STATS CARDS ──────────────────────────────────────────────────────────

  test('muestra tarjetas de estadísticas', async ({ page }) => {
    await expect(page.getByText('Total Faltantes')).toBeVisible();
    await expect(page.getByText('Pendientes').first()).toBeVisible();
    await expect(page.getByText('Confirmados').first()).toBeVisible();
    await expect(page.getByText('Stock Bajo', { exact: true })).toBeVisible();
  });

  // ─── STOCK BAJO ───────────────────────────────────────────────────────────

  test('abrir modal de stock bajo', async ({ page }) => {
    await page.getByRole('button', { name: /Stock Bajo/i }).click();

    const modal = page.locator('[role="dialog"][aria-modal="true"]');
    await expect(modal).toBeVisible({ timeout: 5_000 });
    await expect(modal.getByText(/Stock Bajo/)).toBeVisible();

    await modal.getByRole('button', { name: 'Cerrar' }).click();
    await expect(modal).not.toBeVisible({ timeout: 5_000 });
  });

  // ─── CREAR FALTANTE ───────────────────────────────────────────────────────

  test('crear nuevo faltante manual', async ({ page }) => {
    const btnAgregar = page.getByRole('button', { name: 'Agregar Faltante' });
    if (!await btnAgregar.isVisible()) {
      test.skip(true, 'Faltantes manuales no habilitados en settings');
      return;
    }

    await btnAgregar.click();
    const modal = page.locator('[role="dialog"][aria-modal="true"]');
    await expect(modal.getByRole('heading', { name: 'Nuevo Faltante' })).toBeVisible();

    await modal.locator('textarea').fill(DESC);
    // Pedido: GENERAL por defecto

    await modal.getByRole('button', { name: 'Guardar Faltante' }).click();
    await expect(modal).not.toBeVisible({ timeout: 8_000 });

    await expect(page.locator('tbody tr').filter({ hasText: DESC })).toBeVisible({ timeout: 5_000 });
  });

  // ─── EDITAR FALTANTE ──────────────────────────────────────────────────────

  test('editar faltante existente', async ({ page }) => {
    const fila = page.locator('tbody tr').filter({ hasText: DESC });
    await expect(fila).toBeVisible({ timeout: 5_000 });

    // Primer botón: editar (lápiz amarillo)
    await fila.locator('button').nth(0).click();

    const modal = page.locator('[role="dialog"][aria-modal="true"]');
    await expect(modal.getByRole('heading', { name: 'Editar Faltante' })).toBeVisible();

    const textarea = modal.locator('textarea');
    await textarea.clear();
    await textarea.fill(DESC_EDIT);

    await modal.getByRole('button', { name: 'Guardar Cambios' }).click();
    await expect(modal).not.toBeVisible({ timeout: 8_000 });

    await expect(page.locator('tbody tr').filter({ hasText: DESC_EDIT })).toBeVisible({ timeout: 5_000 });
  });

  // ─── CONFIRMAR SELECCIÓN ──────────────────────────────────────────────────

  test('confirmar faltante seleccionado cambia su estado', async ({ page }) => {
    const fila = page.locator('tbody tr').filter({ hasText: DESC_EDIT });
    await expect(fila).toBeVisible({ timeout: 5_000 });

    // Click en checkbox de la fila
    await fila.locator('input[type="checkbox"]').click();

    // El botón "Confirmar Selección" debe aparecer
    const btnConfirmar = page.getByRole('button', { name: /Confirmar Selección/i });
    await expect(btnConfirmar).toBeVisible();
    await btnConfirmar.click();

    // Después de confirmar, el badge "Confirmado" aparece en la fila
    await expect(
      page.locator('tbody tr').filter({ hasText: DESC_EDIT }).getByText('Confirmado')
    ).toBeVisible({ timeout: 8_000 });
  });

  // ─── ELIMINAR ─────────────────────────────────────────────────────────────

  test('eliminar faltante', async ({ page }) => {
    const fila = page.locator('tbody tr').filter({ hasText: DESC_EDIT });
    await expect(fila).toBeVisible({ timeout: 5_000 });

    page.on('dialog', d => d.accept());
    // Segundo botón: eliminar (basurero rojo)
    await fila.locator('button').nth(1).click();

    await expect(fila).not.toBeVisible({ timeout: 8_000 });
  });

  // ─── FILTROS ──────────────────────────────────────────────────────────────

  test('filtrar por estado Pendientes', async ({ page }) => {
    const selectEstado = page.locator('select').first();
    await selectEstado.selectOption('0'); // Pendientes
    await page.getByRole('button', { name: 'Aplicar' }).click();
    await page.waitForLoadState('networkidle');

    // La tabla no muestra registros "Confirmado" (todos son Pendiente)
    await expect(page.locator('tbody')).not.toContainText('Confirmado');
  });

});
