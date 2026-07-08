# Tests E2E con Playwright — Tlapalería TOTORO

Documentación completa de la suite de pruebas end-to-end implementada con Playwright. Cubre configuración, ejecución, arquitectura y casos de prueba por módulo.

---

## Índice

1. [Requisitos previos](#1-requisitos-previos)
2. [Configuración del entorno](#2-configuración-del-entorno)
3. [Ejecutar los tests](#3-ejecutar-los-tests)
4. [Arquitectura de la suite](#4-arquitectura-de-la-suite)
5. [Módulos de prueba](#5-módulos-de-prueba)
6. [Tests condicionales y dependencias de datos](#6-tests-condicionales-y-dependencias-de-datos)
7. [Artefactos generados](#7-artefactos-generados)
8. [Solución de problemas](#8-solución-de-problemas)

---

## 1. Requisitos previos

Antes de ejecutar los tests, verificar que lo siguiente esté en orden:

| Requisito | Detalle |
|-----------|---------|
| Node.js | v18 o superior |
| PHP / Laravel | Servidor corriendo en `http://127.0.0.1:8000` |
| Base de datos | Seeder ejecutado con usuario admin (`admin@admin.com`) |
| Navegadores Playwright | Instalados con `pnpm exec playwright install` |

**Usuario administrador requerido:**

| Campo | Valor |
|-------|-------|
| Email | `admin@admin.com` |
| Contraseña | `pasword123` |
| Rol | `administrador` |

Si el usuario no existe, ejecutar los seeders de Laravel:

```bash
php artisan db:seed
```

---

## 2. Configuración del entorno

### Iniciar el servidor de desarrollo

Los tests apuntan a `http://127.0.0.1:8000`. Asegurarse de que el servidor esté activo antes de correr cualquier test:

```bash
php artisan serve
```

### Instalar dependencias de Playwright (primera vez)

```bash
pnpm install
pnpm exec playwright install
```

Esto descarga los navegadores necesarios (Chromium, Firefox, WebKit).

### Archivo de configuración

El archivo `playwright.config.ts` en la raíz del proyecto define el comportamiento global:

- **URL base:** `http://127.0.0.1:8000`
- **Ejecución:** Secuencial (1 worker, sin paralelismo — los tests comparten BD)
- **Locale:** `es-MX` / Zona horaria `America/Mexico_City`
- **Capturas:** Solo en caso de fallo
- **Video:** Retenido solo en caso de fallo
- **Traza:** Activada en el primer reintento

---

## 3. Ejecutar los tests

### Comando principal (modo silencioso, sin UI)

```bash
pnpm test
```

Equivale a `playwright test`. Ejecuta toda la suite en modo headless y muestra resultados en consola.

### Con interfaz gráfica (recomendado para depuración)

```bash
pnpm run test:ui
```

Abre la UI interactiva de Playwright donde se puede seleccionar y ejecutar tests individuales, ver el árbol de componentes y reproducir pasos.

### Con navegador visible

```bash
pnpm run test:headed
```

Ejecuta los tests con el navegador abierto para observar las interacciones en tiempo real.

### Ver el reporte HTML del último run

```bash
pnpm run test:report
```

Abre el reporte HTML generado en `playwright-report/`. Incluye resultados por test, capturas de pantalla en fallos y trazas.

### Ejecutar un módulo específico

```bash
pnpm exec playwright test tests/e2e/ventas/ventas.spec.ts
```

### Ejecutar con nombre de test (filtro por texto)

```bash
pnpm exec playwright test --grep "registrar venta"
```

### Ejecutar con reintento en fallos

```bash
pnpm exec playwright test --retries=1
```

---

## 4. Arquitectura de la suite

### Proyectos de Playwright

La suite usa dos proyectos encadenados:

```
setup  →  sistema
```

1. **`setup`** — Ejecuta `auth.setup.ts` una sola vez. Hace login con el admin y guarda el estado de sesión (cookies + `localStorage`) en `tests/e2e/.auth/user.json`.

2. **`sistema`** — Ejecuta todos los archivos `*.spec.ts`. Reutiliza la sesión guardada, por lo que ningún test necesita hacer login individualmente.

### Archivo de autenticación

`tests/e2e/auth.setup.ts`

```
1. Navega a /login
2. Verifica el heading "Tlapalería TOTORO"
3. Llena email y contraseña del admin
4. Hace clic en "Iniciar Sesión"
5. Espera redirección a /dashboard con heading "Bienvenido"
6. Guarda el estado completo en .auth/user.json
```

> **Nota:** El archivo `.auth/user.json` es generado automáticamente. No debe ser commiteado al repositorio (está en `.gitignore`).

### Ejecución secuencial

Los tests se ejecutan con un solo worker (`workers: 1`) porque comparten la misma base de datos. El orden de ejecución sigue el orden de los archivos en el directorio `tests/e2e/`.

### Patrones de datos de prueba

Los tests que crean datos usan timestamps para evitar colisiones:

```ts
const ts = Date.now();
const NOMBRE = `Producto Test ${ts}`;
```

Los tests con `test.describe.serial` garantizan que sus casos internos corran en secuencia y con estado compartido (el producto creado en el test 1 es visible en el test 2).

---

## 5. Módulos de prueba

### 5.1 Autenticación — `auth/login.spec.ts`

Prueba el flujo de login **sin** sesión preexistente.

| # | Caso de prueba | Descripción |
|---|---------------|-------------|
| 1 | muestra el formulario correctamente | Verifica que el formulario de login cargue con sus campos |
| 2 | login exitoso redirige al dashboard | Credenciales válidas → redirección a `/dashboard` |
| 3 | credenciales incorrectas muestra error | Credenciales inválidas → mensaje de error visible |
| 4 | rutas protegidas redirigen a login | Acceder a `/dashboard` sin sesión → redirección a `/login` |

**Ruta:** `/login`

---

### 5.2 Dashboard — `dashboard/dashboard.spec.ts`

| # | Caso de prueba | Descripción |
|---|---------------|-------------|
| 1 | muestra saludo con nombre del usuario | El heading de bienvenida incluye el nombre del admin |
| 2 | muestra tarjetas de estadísticas principales | KPIs visibles (ventas, productos, etc.) |
| 3 | muestra sección de ventas recientes | Tabla/lista de últimas ventas |
| 4 | carga sin errores de JavaScript | No hay errores JS en consola al cargar |
| 5 | los accesos directos a módulos funcionan | Los links de navegación rápida llevan al módulo correcto |

**Ruta:** `/dashboard`

---

### 5.3 Categorías — `categorias/categorias.spec.ts`

| # | Caso de prueba | Descripción |
|---|---------------|-------------|
| 1 | crear nueva categoría | Abre modal, llena nombre, guarda y verifica en tabla |
| 2 | filtrar categorías por nombre | El buscador filtra la lista en tiempo real |
| 3 | editar categoría existente | Abre modal de edición, modifica nombre, guarda |
| 4 | cancelar modal no guarda cambios | Cerrar el modal sin guardar no afecta los datos |
| 5 | eliminar categoría sin productos | Confirmar diálogo elimina la categoría de la lista |

**Ruta:** `/categorias`

---

### 5.4 Productos — `productos/productos.spec.ts`

Usa `test.describe.serial` — los casos se ejecutan en orden y comparten estado.

| # | Caso de prueba | Descripción |
|---|---------------|-------------|
| 1 | crear producto nuevo | Llena nombre, descripción, código de barras y precio |
| 2 | buscar producto por nombre | Filtro por nombre muestra la fila correcta |
| 3 | buscar producto por código de barras | Filtro por barcode localiza el producto |
| 4 | editar producto existente | Cambia nombre y precio; verifica actualización |
| 5 | el precio editado se muestra correctamente en la tabla | Formato MXN visible en la fila |
| 6 | eliminar producto | Confirmar diálogo; producto desaparece de la tabla |
| 7 | no se puede crear producto sin nombre | Validación HTML5 `required` — modal permanece abierto |

**Ruta:** `/productos`

**Campos del formulario:**
- Nombre (requerido)
- Descripción (opcional)
- Código de barras
- Precio de venta

---

### 5.5 Ventas — `ventas/ventas.spec.ts`

**Setup automático:** Antes de correr los tests, el `beforeAll` crea un producto de prueba con inventario de 100 unidades. El `afterAll` lo elimina.

| # | Caso de prueba | Descripción |
|---|---------------|-------------|
| 1 | registrar venta libre | Requiere que "Ventas Libres" esté habilitado en Settings; llena descripción y monto |
| 2 | registrar venta de catálogo | Busca producto por nombre, agrega al carrito (cantidad 2), completa la venta |
| 3 | ver detalles de una venta | Abre el modal de detalles de la primera fila; verifica "Total de la Venta" |
| 4 | no se puede registrar venta de catálogo con carrito vacío | Botón "Completar Venta" deshabilitado con carrito vacío |

**Ruta:** `/ventas`

> **Test condicional:** "registrar venta libre" se omite automáticamente (`test.skip`) si la opción `ventasLibresHabilitadas` está desactivada en la configuración del sistema.

---

### 5.6 Inventario — `inventario/inventario.spec.ts`

**Setup automático:** `beforeAll` crea un producto de prueba; `afterAll` lo elimina junto con su inventario.

| # | Caso de prueba | Descripción |
|---|---------------|-------------|
| 1 | crear entrada de inventario para un producto | Selecciona sucursal y producto, llena cantidad y stock mínimo |
| 2 | buscar producto en inventario | Filtro de búsqueda localiza el registro |
| 3 | ajustar stock — entrada de 10 unidades | Modal de ajuste; tipo "Entrada"; verifica incremento |
| 4 | ajustar stock — salida de 5 unidades | Modal de ajuste; tipo "Salida"; verifica decremento |
| 5 | editar stock mínimo | Modifica el umbral de alerta de stock bajo |
| 6 | eliminar inventario | Confirmar diálogo elimina el registro |

**Ruta:** `/inventario`

---

### 5.7 Proveedores — `proveedores/proveedores.spec.ts`

| # | Caso de prueba | Descripción |
|---|---------------|-------------|
| 1 | crear proveedor nuevo | Llena empresa, contacto, teléfono, email y dirección |
| 2 | filtrar proveedores por nombre | Búsqueda en tiempo real por nombre de empresa |
| 3 | editar proveedor existente | Modifica datos y guarda |
| 4 | eliminar proveedor | Confirmar diálogo; proveedor desaparece |
| 5 | cancelar modal no guarda cambios | Cerrar sin guardar no persiste datos |

**Ruta:** `/proveedores`

---

### 5.8 Usuarios — `usuarios/usuarios.spec.ts`

| # | Caso de prueba | Descripción |
|---|---------------|-------------|
| 1 | muestra tarjetas de estadísticas | Total, Con Rol, Sin Rol, Verificados |
| 2 | la tabla lista al menos un usuario | Al menos el admin aparece en la tabla |
| 3 | ver detalles de un usuario | Modal con información del usuario |
| 4 | filtrar usuarios sin rol asignado | Filtro "Sin Rol" muestra solo usuarios sin asignar |
| 5 | el admin puede ver los selects de rol y sucursal | Controles de asignación visibles para administradores |

**Ruta:** `/usuarios`

---

### 5.9 Sucursales — `sucursales/sucursales.spec.ts`

Usa `test.describe.serial` — los casos se ejecutan en orden.

| # | Caso de prueba | Descripción |
|---|---------------|-------------|
| 1 | crear nueva sucursal | Llena nombre, código, teléfono y gerente |
| 2 | filtrar sucursales por nombre | Buscador filtra la lista |
| 3 | editar sucursal existente | Modifica datos de la sucursal de prueba |
| 4 | desactivar y reactivar sucursal no principal | Toggle de estado activo/inactivo |
| 5 | eliminar sucursal no principal | Elimina la sucursal de prueba creada |
| 6 | cancelar modal no crea sucursal | Cerrar el modal sin guardar no crea registro |

**Ruta:** `/sucursales`

> **Nota:** La sucursal principal del sistema no puede eliminarse. Los tests operan sobre sucursales de prueba secundarias.

---

### 5.10 Reportes — `reportes/reportes.spec.ts`

| # | Caso de prueba | Descripción |
|---|---------------|-------------|
| 1 | la página carga con datos del corte del día | Estadísticas del día actual visibles |
| 2 | shortcut Hoy aplica fechas de hoy | Botón "Hoy" establece el rango de fecha de hoy |
| 3 | shortcut Ayer aplica fecha de ayer | Botón "Ayer" establece la fecha de ayer |
| 4 | shortcut Semana aplica rango de la semana actual | Rango lunes–domingo de la semana en curso |
| 5 | shortcut Mes aplica rango del mes actual | Rango 1–último día del mes en curso |
| 6 | los botones de exportación PDF y CSV están presentes | Botones visibles y habilitados |
| 7 | root puede filtrar por sucursal | Select de sucursal disponible para el admin raíz |

**Ruta:** `/reportes`

---

### 5.11 Auditoría — `auditoria/auditoria.spec.ts`

| # | Caso de prueba | Descripción |
|---|---------------|-------------|
| 1 | muestra el log de actividad | Tabla con registros de actividad del sistema |
| 2 | filtrar actividad por texto | Búsqueda de texto libre en el log |
| 3 | filtrar por tipo de modelo | Select de modelo (Producto, Venta, etc.) |
| 4 | filtrar por usuario | Select de usuario filtra las entradas |
| 5 | limpiar filtros restaura la lista completa | Botón "Limpiar" restablece todos los filtros |
| 6 | muestra controles de paginación | Paginación visible cuando hay múltiples páginas |

**Ruta:** `/auditoria`

---

### 5.12 Configuración del Sistema — `settings/settings.spec.ts`

| # | Caso de prueba | Descripción |
|---|---------------|-------------|
| 1 | muestra advertencia de configuración sensible | Banner de aviso visible |
| 2 | puede alternar la opción de Ventas Libres | Toggle activa/desactiva la feature |
| 3 | puede alternar la opción de Faltantes Manuales | Toggle activa/desactiva la feature |
| 4 | muestra campo de umbral de stock bajo | Input numérico para el threshold |
| 5 | botón generar faltantes automáticos está presente | Botón de acción visible |
| 6 | muestra estadísticas del sistema | Contadores de productos, ventas, usuarios, etc. |

**Ruta:** `/settings`

> **Acceso restringido:** Solo disponible para usuarios con rol `administrador`.

---

### 5.13 Gastos — `gastos/gastos.spec.ts`

Usa `test.describe.serial`.

| # | Caso de prueba | Descripción |
|---|---------------|-------------|
| 1 | registrar nuevo gasto | Llena descripción y monto; guarda |
| 2 | el gasto registrado muestra el monto correcto | Verifica el monto en la tabla |
| 3 | cancelar formulario no crea gasto | Cerrar sin guardar no persiste |
| 4 | eliminar gasto registrado | Confirmar diálogo; gasto desaparece |
| 5 | muestra estadísticas de gastos | KPIs de gastos del período visibles |

**Ruta:** `/gastos`

---

### 5.14 Faltantes — `faltantes/faltantes.spec.ts`

| # | Caso de prueba | Descripción |
|---|---------------|-------------|
| 1 | muestra tarjetas de estadísticas | Total, Pendientes, Confirmados, Stock Bajo |
| 2 | abrir modal de stock bajo | Modal con productos bajo el umbral |
| 3 | crear nuevo faltante manual | Requiere que "Faltantes Manuales" esté habilitado; llena datos |
| 4 | editar faltante existente | Modifica el faltante y guarda |
| 5 | confirmar faltante seleccionado cambia su estado | Estado cambia de Pendiente a Confirmado |
| 6 | eliminar faltante | Confirmar diálogo; faltante desaparece |
| 7 | filtrar por estado Pendientes | Filtro de estado muestra solo pendientes |

**Ruta:** `/faltantes`

> **Test condicional:** "crear nuevo faltante manual" se omite si la opción no está habilitada en Settings.

---

### 5.15 Cuentas por Cobrar — `cuentas-por-cobrar/cuentas-por-cobrar.spec.ts`

| # | Caso de prueba | Descripción |
|---|---------------|-------------|
| 1 | la página carga correctamente | Heading y estructura visible |
| 2 | registrar nueva venta a crédito | Modo "nuevo cliente"; llena nombre y monto |
| 3 | registrar cobro en cuenta existente | Abre cuenta, registra abono |
| 4 | editar cuenta por cobrar | Modifica datos de la cuenta |
| 5 | buscar cuenta por cliente | Filtro por nombre de cliente |
| 6 | eliminar cuenta por cobrar | Confirmar diálogo; cuenta eliminada |

**Ruta:** `/cuentas-por-cobrar`

---

### 5.16 Cuentas por Pagar — `cuentas-por-pagar/cuentas-por-pagar.spec.ts`

| # | Caso de prueba | Descripción |
|---|---------------|-------------|
| 1 | la página carga correctamente | Heading y estructura visible |
| 2 | registrar nueva cuenta por pagar | Selecciona proveedor, llena monto y descripción |
| 3 | registrar pago en cuenta existente | Abre cuenta, registra abono al proveedor |
| 4 | editar cuenta por pagar | Modifica datos de la cuenta |
| 5 | eliminar cuenta por pagar | Confirmar diálogo; cuenta eliminada |
| 6 | filtrar por estado pendiente | Filtro muestra solo cuentas sin liquidar |

**Ruta:** `/cuentas-por-pagar`

---

## 6. Tests condicionales y dependencias de datos

### Tests que se omiten automáticamente

Algunos tests usan `test.skip()` para saltar si una feature del sistema está deshabilitada:

| Test | Condición para ejecutarse |
|------|--------------------------|
| `registrar venta libre` | `ventasLibresHabilitadas = true` en Settings |
| `crear nuevo faltante manual` | `faltantesManualesHabilitados = true` en Settings |

Para activar estas features antes de correr los tests, ir a `/settings` y activar los toggles correspondientes.

### Tests con setup/teardown de datos

Los siguientes módulos crean y limpian sus propios datos de prueba automáticamente:

| Módulo | Crea en beforeAll | Elimina en afterAll |
|--------|------------------|---------------------|
| Ventas | Producto + Inventario de prueba | Producto de prueba |
| Inventario | Producto de prueba | Producto + registro de inventario |

Estos datos usan timestamps (`Date.now()`) como parte del nombre para evitar conflictos con datos reales.

### Diálogos de confirmación

Los tests que eliminan registros usan el handler `page.on('dialog', d => d.accept())` para aceptar automáticamente los diálogos de confirmación del navegador.

---

## 7. Artefactos generados

| Ruta | Contenido |
|------|-----------|
| `playwright-report/` | Reporte HTML con resultados, capturas y trazas |
| `test-results/` | Artefactos crudos de cada test (screenshots, videos en fallo) |
| `tests/e2e/.auth/user.json` | Estado de sesión del admin (generado por `auth.setup.ts`) |

Para ver el reporte del último run:

```bash
pnpm run test:report
```

---

## 8. Solución de problemas

### Error: "No se pudo conectar a http://127.0.0.1:8000"

El servidor de Laravel no está corriendo. Ejecutar:

```bash
php artisan serve
```

### Error: "auth/user.json not found" o fallo en setup

El archivo de sesión no fue generado. Puede ocurrir si el usuario admin no existe en la BD. Verificar con:

```bash
php artisan tinker
>>> App\Models\User::where('email', 'admin@admin.com')->first()
```

Si es null, ejecutar:

```bash
php artisan db:seed
```

### Los tests fallan por datos residuales

Si un test anterior falló a mitad del `afterAll`, puede haber quedado un producto/inventario de prueba. Buscar registros con el patrón `*Test*` o `*E2E*` en la UI y eliminarlos manualmente antes de volver a correr.

### Un test falla por timeout

Aumentar el timeout de un test específico:

```ts
test('mi test', async ({ page }) => {
  test.setTimeout(60_000); // 60 segundos
  // ...
});
```

O de forma global en `playwright.config.ts`:

```ts
use: {
  actionTimeout: 15_000,
  navigationTimeout: 30_000,
}
```

### Ver la traza de un test fallido

```bash
pnpm exec playwright show-trace test-results/<nombre-del-test>/trace.zip
```

### Correr un solo test en modo debug paso a paso

```bash
pnpm exec playwright test tests/e2e/productos/productos.spec.ts --debug
```

---

## Resumen rápido de comandos

```bash
# Todos los tests (headless)
pnpm test

# Con UI interactiva
pnpm run test:ui

# Con navegador visible


# Ver reporte HTML
pnpm run test:report

# Un módulo específico
pnpm exec playwright test tests/e2e/ventas/ventas.spec.ts

# Filtrar por nombre de test
pnpm exec playwright test --grep "registrar venta"

# Modo debug (paso a paso)
pnpm exec playwright test tests/e2e/productos/productos.spec.ts --debug
```

---

*Última actualización: Mayo 2026*
