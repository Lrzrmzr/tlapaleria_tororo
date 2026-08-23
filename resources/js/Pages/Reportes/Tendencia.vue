<script setup>
import { ref, computed } from 'vue';
import { router, Link } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head } from '@inertiajs/vue3';

const props = defineProps({
    periodos: Array,
    totales:  Object,
    filters:  Object,
    branches: Array,
    user:     Object,
});

const nombresMes = ['Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio', 'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre'];

// ── Filtros locales ──────────────────────────────────────────────────────────
const granularidad = ref(props.filters.granularidad);
const anio          = ref(props.filters.anio ?? new Date().getFullYear());
const mes           = ref(props.filters.mes ?? new Date().getMonth() + 1);
const branchId      = ref(props.filters.branch_id ?? '');

function aplicarFiltros() {
    const params = { granularidad: granularidad.value };
    if (granularidad.value === 'dia') {
        params.anio = anio.value;
        params.mes  = mes.value;
    } else if (granularidad.value === 'mes') {
        params.anio = anio.value;
    }
    if (branchId.value) params.branch_id = branchId.value;

    router.get(route('reportes.tendencia'), params, { preserveState: true, preserveScroll: true });
}

// ── Helpers ──────────────────────────────────────────────────────────────────
const fmt = (n) =>
    new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN', minimumFractionDigits: 2 }).format(n ?? 0);

const utilidadPositiva = computed(() => (props.totales.utilidad_neta ?? 0) >= 0);

const maxVentas = computed(() =>
    Math.max(1, ...props.periodos.map(p => p.total_ventas))
);

const tituloPeriodo = computed(() => {
    if (granularidad.value === 'dia')  return `${nombresMes[mes.value - 1]} ${anio.value}`;
    if (granularidad.value === 'mes')  return `Año ${anio.value}`;
    return 'Últimos años';
});
</script>

<template>
    <Head title="Tendencia de Ganancias" />
    <AuthenticatedLayout>
        <div class="p-6 space-y-6">

            <!-- ── Encabezado ──────────────────────────────────────────────── -->
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                <div>
                    <h1 class="text-2xl font-bold text-gray-900 dark:text-white">Tendencia de Ganancias</h1>
                    <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">{{ tituloPeriodo }}</p>
                </div>

                <div class="flex gap-2 text-sm">
                    <Link :href="route('reportes.index')"
                          class="px-4 py-2 rounded-lg border border-gray-300 dark:border-gray-600 text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 transition-colors">
                        Corte por rango
                    </Link>
                    <span class="px-4 py-2 rounded-lg bg-blue-600 text-white font-medium">
                        Tendencia por período
                    </span>
                </div>
            </div>

            <!-- ── Filtros ─────────────────────────────────────────────────── -->
            <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-4">
                <form @submit.prevent="aplicarFiltros" class="flex flex-wrap gap-3 items-end">
                    <div>
                        <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">Agrupar por</label>
                        <select v-model="granularidad" @change="aplicarFiltros"
                                class="rounded-lg border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500">
                            <option value="dia">Día</option>
                            <option value="mes">Mes</option>
                            <option value="anio">Año</option>
                        </select>
                    </div>

                    <div v-if="granularidad === 'dia' || granularidad === 'mes'">
                        <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">Año</label>
                        <select v-model.number="anio"
                                class="rounded-lg border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500">
                            <option v-for="a in filters.anios_disponibles" :key="a" :value="a">{{ a }}</option>
                        </select>
                    </div>

                    <div v-if="granularidad === 'dia'">
                        <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">Mes</label>
                        <select v-model.number="mes"
                                class="rounded-lg border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500">
                            <option v-for="(nombre, i) in nombresMes" :key="i" :value="i + 1">{{ nombre }}</option>
                        </select>
                    </div>

                    <div v-if="user.isRoot && branches.length">
                        <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">Sucursal</label>
                        <select v-model="branchId"
                                class="rounded-lg border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500">
                            <option value="">Todas</option>
                            <option v-for="b in branches" :key="b.id" :value="b.id">{{ b.name }}</option>
                        </select>
                    </div>

                    <button type="submit"
                            class="px-5 py-2 bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium rounded-lg transition-colors">
                        Aplicar
                    </button>
                </form>
            </div>

            <!-- ── Tarjetas de resumen ─────────────────────────────────────── -->
            <div class="grid grid-cols-2 sm:grid-cols-4 gap-4">
                <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-4">
                    <span class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wide">Ventas</span>
                    <div class="text-xl font-bold text-gray-900 dark:text-white mt-1">{{ fmt(totales.total_ventas) }}</div>
                    <div class="text-xs text-gray-500 dark:text-gray-400 mt-1">{{ totales.count_ventas }} transacciones</div>
                </div>

                <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-4">
                    <span class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wide">Utilidad Bruta</span>
                    <div class="text-xl font-bold text-blue-600 dark:text-blue-400 mt-1">{{ fmt(totales.utilidad_bruta) }}</div>
                    <div class="text-xs text-gray-500 dark:text-gray-400 mt-1">Solo ventas de catálogo</div>
                </div>

                <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-4">
                    <span class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wide">Gastos</span>
                    <div class="text-xl font-bold text-orange-600 dark:text-orange-400 mt-1">{{ fmt(totales.total_gastos) }}</div>
                </div>

                <div :class="[
                    'rounded-xl border p-4',
                    utilidadPositiva
                        ? 'bg-emerald-50 dark:bg-emerald-900/20 border-emerald-200 dark:border-emerald-700'
                        : 'bg-red-50 dark:bg-red-900/20 border-red-200 dark:border-red-700'
                ]">
                    <span class="text-xs font-medium uppercase tracking-wide"
                          :class="utilidadPositiva ? 'text-emerald-700 dark:text-emerald-400' : 'text-red-700 dark:text-red-400'">
                        Utilidad Neta
                    </span>
                    <div class="text-xl font-bold mt-1"
                         :class="utilidadPositiva ? 'text-emerald-700 dark:text-emerald-300' : 'text-red-700 dark:text-red-300'">
                        {{ fmt(totales.utilidad_neta) }}
                    </div>
                    <div class="text-xs mt-1"
                         :class="utilidadPositiva ? 'text-emerald-600 dark:text-emerald-400' : 'text-red-600 dark:text-red-400'">
                        Utilidad bruta − Gastos
                    </div>
                </div>
            </div>

            <p class="text-xs text-gray-400 dark:text-gray-500">
                La utilidad bruta solo incluye ventas de catálogo con costo conocido; las ventas libres no descuentan costo por diseño.
            </p>

            <!-- ── Tabla por período ───────────────────────────────────────── -->
            <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-5">
                <div v-if="periodos.every(p => p.total_ventas === 0 && p.total_gastos === 0)"
                     class="text-center text-gray-400 dark:text-gray-500 py-12 text-sm">
                    Sin movimientos en este período
                </div>

                <div v-else class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="text-left text-xs text-gray-500 dark:text-gray-400 border-b border-gray-200 dark:border-gray-700">
                                <th class="pb-2 font-medium">Período</th>
                                <th class="pb-2 font-medium">Ventas</th>
                                <th class="pb-2 font-medium text-right">Utilidad Bruta</th>
                                <th class="pb-2 font-medium text-right">Gastos</th>
                                <th class="pb-2 font-medium text-right">Utilidad Neta</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                            <tr v-for="p in periodos" :key="p.periodo" class="hover:bg-gray-50 dark:hover:bg-gray-700/50">
                                <td class="py-2 text-gray-800 dark:text-gray-200 whitespace-nowrap">{{ p.label }}</td>
                                <td class="py-2 w-1/3">
                                    <div class="flex items-center gap-2">
                                        <div class="flex-1 h-2 bg-gray-100 dark:bg-gray-700 rounded-full overflow-hidden min-w-[60px]">
                                            <div class="h-full rounded-full bg-blue-500 transition-all"
                                                 :style="{ width: (p.total_ventas / maxVentas * 100).toFixed(1) + '%' }">
                                            </div>
                                        </div>
                                        <span class="text-xs text-gray-500 dark:text-gray-400 tabular-nums whitespace-nowrap">{{ fmt(p.total_ventas) }}</span>
                                    </div>
                                </td>
                                <td class="py-2 text-right text-gray-700 dark:text-gray-300 tabular-nums">{{ fmt(p.utilidad_bruta) }}</td>
                                <td class="py-2 text-right text-orange-600 dark:text-orange-400 tabular-nums">{{ fmt(p.total_gastos) }}</td>
                                <td class="py-2 text-right font-semibold tabular-nums"
                                    :class="p.utilidad_neta >= 0 ? 'text-emerald-600 dark:text-emerald-400' : 'text-red-600 dark:text-red-400'">
                                    {{ fmt(p.utilidad_neta) }}
                                </td>
                            </tr>
                        </tbody>
                        <tfoot>
                            <tr class="border-t-2 border-gray-200 dark:border-gray-600">
                                <td class="pt-2 font-semibold text-gray-700 dark:text-gray-300" colspan="2">Total</td>
                                <td class="pt-2 text-right font-bold text-gray-900 dark:text-white tabular-nums">{{ fmt(totales.utilidad_bruta) }}</td>
                                <td class="pt-2 text-right font-bold text-orange-600 dark:text-orange-400 tabular-nums">{{ fmt(totales.total_gastos) }}</td>
                                <td class="pt-2 text-right font-bold tabular-nums"
                                    :class="utilidadPositiva ? 'text-emerald-600 dark:text-emerald-400' : 'text-red-600 dark:text-red-400'">
                                    {{ fmt(totales.utilidad_neta) }}
                                </td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
