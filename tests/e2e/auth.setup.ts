import { test as setup, expect } from '@playwright/test';
import { fileURLToPath } from 'url';
import path from 'path';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const authFile = path.join(__dirname, '.auth/user.json');

/**
 * Se ejecuta UNA vez antes de todos los tests.
 * Hace login y guarda cookies + localStorage en .auth/user.json
 * para que cada test no tenga que hacer login individualmente.
 */
setup('autenticar como admin', async ({ page }) => {
  await page.goto('/login');

  // Esperar a que el formulario esté listo
  await expect(page.locator('h1')).toContainText('Tlapalería TOTORO');

  await page.fill('#email', 'admin@admin.com');
  await page.fill('#password', 'pasword123');
  await page.getByRole('button', { name: /iniciar sesión/i }).click();

  // Esperamos la URL + un elemento del dashboard; networkidle falla con Inertia/Vite HMR
  await page.waitForURL('**/dashboard', { timeout: 20_000 });
  await expect(page.getByRole('heading', { name: /Bienvenido/i })).toBeVisible({ timeout: 10_000 });

  // Las ventas, cobros y pagos en efectivo ahora exigen un turno de caja
  // abierto en la sucursal. Se abre uno en cada sucursal activa para que
  // el resto de la suite (ventas, cuentas por cobrar/pagar) no falle por
  // falta de turno — root elige sucursal explícitamente porque no tiene una propia.
  await page.goto('/caja');
  const branchIds = (
    await page.locator('select').first().locator('option').all()
  ).map((o) => o.getAttribute('value'));

  for (const branchId of await Promise.all(branchIds)) {
    if (!branchId) continue;

    await page.goto(`/caja?branch_id=${branchId}`);
    const yaAbierta = await page.getByText('Turno abierto').isVisible().catch(() => false);
    if (yaAbierta) continue;

    await page.getByRole('button', { name: 'Abrir turno' }).click();
    const modal = page.locator('.fixed.inset-0.z-50').filter({ hasText: 'Abrir turno de caja' });
    await modal.locator('select').selectOption(branchId);
    await modal.locator('input[type="number"]').fill('1000');
    await modal.getByRole('button', { name: 'Abrir' }).click();
    await expect(page.getByText('Turno abierto')).toBeVisible({ timeout: 5_000 });
  }

  // Guardar sesión completa (cookies + localStorage con el access_token)
  await page.context().storageState({ path: authFile });
});
