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

  // Guardar sesión completa (cookies + localStorage con el access_token)
  await page.context().storageState({ path: authFile });
});
