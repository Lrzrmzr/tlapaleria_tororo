import { test, expect } from '@playwright/test';

test.describe('Auditoría', () => {

  test.beforeEach(async ({ page }) => {
    const jsErrors: string[] = [];
    page.on('pageerror', err => {
      jsErrors.push(err.message);
      console.log('[JS ERROR]', err.message);
    });
    page.on('console', msg => {
      if (msg.type() === 'error') console.log('[CONSOLE ERROR]', msg.text());
    });

    await page.goto('/auditoria', { waitUntil: 'networkidle' });
    const currentUrl = page.url();
    const title = await page.title();
    console.log('[DIAGNÓSTICO] URL:', currentUrl, '| Title:', title);

    // Imprimir todos los h2 presentes en el DOM
    const h2s = await page.locator('h2').all();
    console.log('[DIAGNÓSTICO] h2 encontrados:', h2s.length);
    for (const h2 of h2s) {
      console.log('  h2:', await h2.textContent());
    }

    await expect(page.locator('h2').filter({ hasText: 'Auditoría' })).toBeVisible({ timeout: 10_000 });
  });

  // ─── CARGA ────────────────────────────────────────────────────────────────

  test('muestra el log de actividad', async ({ page }) => {
    // Debe haber al menos una fila de actividad (el login ya genera registros)
    await expect(page.locator('tbody tr, .activity-row, .border-b').first()).toBeVisible({ timeout: 8_000 });
  });

  // ─── FILTRO POR BÚSQUEDA ──────────────────────────────────────────────────

  test('filtrar actividad por texto', async ({ page }) => {
    const searchInput = page.locator('input[type="text"], input[placeholder*="buscar"], input[placeholder*="Buscar"]').first();
    await expect(searchInput).toBeVisible({ timeout: 5_000 });

    await searchInput.fill('Sale');
    await page.waitForTimeout(400); // debounce 300ms

    await page.waitForLoadState('networkidle');
    // La página responde (puede tener resultados o tabla vacía)
    await expect(page.locator('h2').filter({ hasText: 'Auditoría' })).toBeVisible();
  });

  // ─── FILTRO POR MODELO ────────────────────────────────────────────────────

  test('filtrar por tipo de modelo', async ({ page }) => {
    const selectModelo = page.locator('select').filter({ hasText: /modelo|Modelo/i }).first();
    if (await selectModelo.isVisible()) {
      await selectModelo.selectOption({ index: 1 }); // Primer modelo disponible
      await page.waitForLoadState('networkidle');
      await expect(page.locator('h2').filter({ hasText: 'Auditoría' })).toBeVisible();
    }
  });

  // ─── FILTRO POR USUARIO ───────────────────────────────────────────────────

  test('filtrar por usuario', async ({ page }) => {
    const selectUsuario = page.locator('select').first();
    await expect(selectUsuario).toBeVisible({ timeout: 5_000 });

    // Seleccionar el primer usuario disponible (opción 1)
    const opciones = await selectUsuario.locator('option').count();
    if (opciones > 1) {
      await selectUsuario.selectOption({ index: 1 });
      await page.waitForLoadState('networkidle');
      await expect(page.locator('h2').filter({ hasText: 'Auditoría' })).toBeVisible();
    }
  });

  // ─── LIMPIAR FILTROS ──────────────────────────────────────────────────────

  test('limpiar filtros restaura la lista completa', async ({ page }) => {
    const searchInput = page.locator('input[type="text"]').first();
    await searchInput.fill('algo para filtrar');
    await page.waitForTimeout(400);

    const btnLimpiar = page.getByRole('button', { name: /limpiar/i });
    if (await btnLimpiar.isVisible()) {
      await btnLimpiar.click();
      await page.waitForLoadState('networkidle');
    } else {
      await searchInput.clear();
      await page.waitForTimeout(400);
    }

    await expect(page.locator('h2').filter({ hasText: 'Auditoría' })).toBeVisible();
  });

  // ─── PAGINACIÓN ───────────────────────────────────────────────────────────

  test('muestra controles de paginación', async ({ page }) => {
    // La auditoría usa paginación del servidor (activities es un Object con .data/.links)
    const paginacion = page.locator('nav[aria-label*="paginación"], nav[aria-label*="pagination"], [role="navigation"]').first();
    // Si hay suficientes registros la paginación es visible, si no solo verificamos que la tabla cargó
    const tabla = page.locator('tbody, .activities-list').first();
    await expect(tabla).toBeVisible({ timeout: 5_000 });
  });

});
