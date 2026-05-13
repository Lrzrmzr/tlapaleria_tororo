<script setup>
import { ref, computed } from 'vue';
import { router } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head } from '@inertiajs/vue3';

const props = defineProps({
    corte:    Object,
    filters:  Object,
    branches: Array,
    user:     Object,
});

// ── Filtros locales ──────────────────────────────────────────────────────────
const fechaDesde = ref(props.filters.fecha_desde);
const fechaHasta = ref(props.filters.fecha_hasta);
const branchId   = ref(props.filters.branch_id ?? '');

function aplicarFiltros() {
    router.get(route('reportes.index'), {
        fecha_desde: fechaDesde.value,
        fecha_hasta: fechaHasta.value,
        branch_id:   branchId.value || undefined,
    }, { preserveState: true, preserveScroll: true });
}

// Atajos de fecha
function setRango(tipo) {
    const hoy = new Date();
    const fmt  = d => d.toISOString().slice(0, 10);

    if (tipo === 'hoy') {
        fechaDesde.value = fmt(hoy);
        fechaHasta.value = fmt(hoy);
    } else if (tipo === 'ayer') {
        const ayer = new Date(hoy); ayer.setDate(hoy.getDate() - 1);
        fechaDesde.value = fmt(ayer);
        fechaHasta.value = fmt(ayer);
    } else if (tipo === 'semana') {
        const lunes = new Date(hoy);
        lunes.setDate(hoy.getDate() - ((hoy.getDay() + 6) % 7));
        fechaDesde.value = fmt(lunes);
        fechaHasta.value = fmt(hoy);
    } else if (tipo === 'mes') {
        fechaDesde.value = fmt(new Date(hoy.getFullYear(), hoy.getMonth(), 1));
        fechaHasta.value = fmt(hoy);
    } else if (tipo === 'mes_anterior') {
        const primerMesAnt = new Date(hoy.getFullYear(), hoy.getMonth() - 1, 1);
        const ultimoMesAnt = new Date(hoy.getFullYear(), hoy.getMonth(), 0);
        fechaDesde.value = fmt(primerMesAnt);
        fechaHasta.value = fmt(ultimoMesAnt);
    }
    aplicarFiltros();
}

// ── URLs de exportación ──────────────────────────────────────────────────────
const exportQuery = computed(() => {
    const p = new URLSearchParams({
        fecha_desde: fechaDesde.value,
        fecha_hasta: fechaHasta.value,
    });
    if (branchId.value) p.set('branch_id', branchId.value);
    return p.toString();
});

const pdfUrl = computed(() => `/reportes/pdf?${exportQuery.value}`);
const csvUrl = computed(() => `/reportes/csv?${exportQuery.value}`);

// ── Helpers ──────────────────────────────────────────────────────────────────
const fmt = (n) =>
    new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN', minimumFractionDigits: 2 }).format(n ?? 0);

const tipoLabel = (t) => t === 'libre' ? 'Venta Libre' : 'Catálogo';

const saldoPositivo = computed(() => (props.corte.saldo_neto ?? 0) >= 0);

// Colores para la barra de ventas por tipo
const tipoColor = (tipo) => tipo === 'libre'
    ? 'bg-violet-500'
    : 'bg-blue-500';

// Porcentaje para barras
const ventasTotalTipo = computed(() =>
    props.corte.ventas_por_tipo.reduce((s, t) => s + t.total, 0) || 1
);
</script>

<template>
    <Head title="Reportes" />
    <AuthenticatedLayout>
        <div class="p-6 space-y-6">

            <!-- ── Encabezado ──────────────────────────────────────────────── -->
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                <div>
                    <h1 class="text-2xl font-bold text-gray-900 dark:text-white">Reportes & Corte de Caja</h1>
                    <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">
                        {{ filters.fecha_desde === filters.fecha_hasta
                            ? filters.fecha_desde
                            : `${filters.fecha_desde} al ${filters.fecha_hasta}` }}
                    </p>
                </div>

                <!-- Botones de exportación -->
                <div class="flex gap-2">
                    <a :href="pdfUrl" target="_blank"
                       class="inline-flex items-center gap-2 px-4 py-2 bg-red-600 hover:bg-red-700 text-white text-sm font-medium rounded-lg transition-colors">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z"/>
                        </svg>
                        PDF
                    </a>
                    <a :href="csvUrl"
                       class="inline-flex items-center gap-2 px-4 py-2 bg-green-600 hover:bg-green-700 text-white text-sm font-medium rounded-lg transition-colors">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/>
                        </svg>
                        CSV / Excel
                    </a>
                </div>
            </div>

            <!-- ── Filtros ─────────────────────────────────────────────────── -->
            <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-4">
                <!-- Atajos rápidos -->
                <div class="flex flex-wrap gap-2 mb-4">
                    <button v-for="(label, tipo) in { hoy:'Hoy', ayer:'Ayer', semana:'Esta semana', mes:'Este mes', mes_anterior:'Mes anterior' }"
                            :key="tipo"
                            @click="setRango(tipo)"
                            class="px-3 py-1.5 text-xs font-medium rounded-lg border border-gray-300 dark:border-gray-600 text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 transition-colors">
                        {{ label }}
                    </button>
                </div>

                <form @submit.prevent="aplicarFiltros" class="flex flex-wrap gap-3 items-end">
                    <div>
                        <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">Desde</label>
                        <input type="date" v-model="fechaDesde"
                               class="rounded-lg border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500" />
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">Hasta</label>
                        <input type="date" v-model="fechaHasta"
                               class="rounded-lg border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500" />
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
            <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-5 gap-4">
                <!-- Ventas -->
                <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-4">
                    <div class="flex items-center justify-between mb-2">
                        <span class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wide">Ventas</span>
                        <span class="w-8 h-8 rounded-full bg-blue-100 dark:bg-blue-900/30 flex items-center justify-center">
                            <svg class="w-4 h-4 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 11-4 0 2 2 0 014 0z"/>
                            </svg>
                        </span>
                    </div>
                    <div class="text-xl font-bold text-gray-900 dark:text-white">{{ fmt(corte.total_ventas) }}</div>
                    <div class="text-xs text-gray-500 dark:text-gray-400 mt-1">{{ corte.count_ventas }} transacciones</div>
                </div>

                <!-- Cobros -->
                <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-4">
                    <div class="flex items-center justify-between mb-2">
                        <span class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wide">Cobros</span>
                        <span class="w-8 h-8 rounded-full bg-emerald-100 dark:bg-emerald-900/30 flex items-center justify-center">
                            <svg class="w-4 h-4 text-emerald-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 14l6-6m-5.5.5h.01m4.99 5h.01M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16l3.5-2 3.5 2 3.5-2 3.5 2z"/>
                            </svg>
                        </span>
                    </div>
                    <div class="text-xl font-bold text-emerald-600 dark:text-emerald-400">{{ fmt(corte.total_cobros) }}</div>
                    <div class="text-xs text-gray-500 dark:text-gray-400 mt-1">Ctas. por cobrar</div>
                </div>

                <!-- Gastos -->
                <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-4">
                    <div class="flex items-center justify-between mb-2">
                        <span class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wide">Gastos</span>
                        <span class="w-8 h-8 rounded-full bg-orange-100 dark:bg-orange-900/30 flex items-center justify-center">
                            <svg class="w-4 h-4 text-orange-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z"/>
                            </svg>
                        </span>
                    </div>
                    <div class="text-xl font-bold text-orange-600 dark:text-orange-400">{{ fmt(corte.total_gastos) }}</div>
                    <div class="text-xs text-gray-500 dark:text-gray-400 mt-1">{{ corte.gastos_detalle.length }} conceptos</div>
                </div>

                <!-- Pagos proveedores -->
                <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-4">
                    <div class="flex items-center justify-between mb-2">
                        <span class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wide">Pagos</span>
                        <span class="w-8 h-8 rounded-full bg-red-100 dark:bg-red-900/30 flex items-center justify-center">
                            <svg class="w-4 h-4 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                            </svg>
                        </span>
                    </div>
                    <div class="text-xl font-bold text-red-600 dark:text-red-400">{{ fmt(corte.total_pagos) }}</div>
                    <div class="text-xs text-gray-500 dark:text-gray-400 mt-1">A proveedores</div>
                </div>

                <!-- Saldo neto -->
                <div :class="[
                    'rounded-xl border p-4',
                    saldoPositivo
                        ? 'bg-emerald-50 dark:bg-emerald-900/20 border-emerald-200 dark:border-emerald-700'
                        : 'bg-red-50 dark:bg-red-900/20 border-red-200 dark:border-red-700'
                ]">
                    <div class="flex items-center justify-between mb-2">
                        <span class="text-xs font-medium uppercase tracking-wide"
                              :class="saldoPositivo ? 'text-emerald-700 dark:text-emerald-400' : 'text-red-700 dark:text-red-400'">
                            Saldo Neto
                        </span>
                        <span :class="[
                            'w-8 h-8 rounded-full flex items-center justify-center',
                            saldoPositivo ? 'bg-emerald-200 dark:bg-emerald-800' : 'bg-red-200 dark:bg-red-800'
                        ]">
                            <svg class="w-4 h-4" :class="saldoPositivo ? 'text-emerald-700' : 'text-red-700'"
                                 fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                      :d="saldoPositivo
                                          ? 'M13 7h8m0 0v8m0-8l-8 8-4-4-6 6'
                                          : 'M13 17h8m0 0V9m0 8l-8-8-4 4-6-6'"/>
                            </svg>
                        </span>
                    </div>
                    <div class="text-xl font-bold"
                         :class="saldoPositivo ? 'text-emerald-700 dark:text-emerald-300' : 'text-red-700 dark:text-red-300'">
                        {{ fmt(corte.saldo_neto) }}
                    </div>
                    <div class="text-xs mt-1"
                         :class="saldoPositivo ? 'text-emerald-600 dark:text-emerald-400' : 'text-red-600 dark:text-red-400'">
                        Ventas + Cobros − Gastos − Pagos
                    </div>
                </div>
            </div>

            <!-- ── Segunda fila: Tipo de ventas + Top productos ─────────────── -->
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">

                <!-- Ventas por tipo -->
                <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-5">
                    <h2 class="text-sm font-semibold text-gray-700 dark:text-gray-300 uppercase tracking-wide mb-4">
                        Ventas por Tipo
                    </h2>

                    <div v-if="corte.ventas_por_tipo.length === 0"
                         class="text-center text-gray-400 dark:text-gray-500 py-8 text-sm">
                        Sin ventas en el período
                    </div>

                    <div v-else class="space-y-4">
                        <div v-for="tipo in corte.ventas_por_tipo" :key="tipo.tipo">
                            <div class="flex justify-between text-sm mb-1">
                                <span class="font-medium text-gray-700 dark:text-gray-300">{{ tipoLabel(tipo.tipo) }}</span>
                                <span class="text-gray-900 dark:text-white font-semibold">{{ fmt(tipo.total) }}</span>
                            </div>
                            <!-- Barra de progreso -->
                            <div class="h-2 bg-gray-100 dark:bg-gray-700 rounded-full overflow-hidden">
                                <div :class="['h-full rounded-full transition-all', tipoColor(tipo.tipo)]"
                                     :style="{ width: (tipo.total / ventasTotalTipo * 100).toFixed(1) + '%' }">
                                </div>
                            </div>
                            <div class="flex gap-4 mt-1 text-xs text-gray-500 dark:text-gray-400">
                                <span>{{ tipo.ventas }} ventas</span>
                                <span>{{ tipo.cantidad }} artículos</span>
                                <span>{{ (tipo.total / ventasTotalTipo * 100).toFixed(1) }}%</span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Top productos -->
                <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-5">
                    <h2 class="text-sm font-semibold text-gray-700 dark:text-gray-300 uppercase tracking-wide mb-4">
                        Top Productos Vendidos
                    </h2>

                    <div v-if="corte.top_productos.length === 0"
                         class="text-center text-gray-400 dark:text-gray-500 py-8 text-sm">
                        Sin ventas de catálogo en el período
                    </div>

                    <div v-else class="space-y-2">
                        <div v-for="(p, i) in corte.top_productos" :key="p.name"
                             class="flex items-center gap-3 py-1.5">
                            <span class="w-6 h-6 rounded-full bg-gray-100 dark:bg-gray-700 text-xs font-bold text-gray-600 dark:text-gray-300 flex items-center justify-center flex-shrink-0">
                                {{ i + 1 }}
                            </span>
                            <span class="flex-1 text-sm text-gray-800 dark:text-gray-200 truncate">{{ p.name }}</span>
                            <span class="text-xs text-gray-500 dark:text-gray-400">×{{ p.cantidad }}</span>
                            <span class="text-sm font-semibold text-gray-900 dark:text-white tabular-nums">{{ fmt(p.total) }}</span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ── Detalle de Gastos ────────────────────────────────────────── -->
            <div v-if="corte.gastos_detalle.length > 0"
                 class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-5">
                <h2 class="text-sm font-semibold text-gray-700 dark:text-gray-300 uppercase tracking-wide mb-4">
                    Detalle de Gastos
                </h2>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="text-left text-xs text-gray-500 dark:text-gray-400 border-b border-gray-200 dark:border-gray-700">
                                <th class="pb-2 font-medium">Descripción</th>
                                <th class="pb-2 font-medium text-right">Monto</th>
                                <th class="pb-2 font-medium">Registrado por</th>
                                <th class="pb-2 font-medium text-right">Fecha</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                            <tr v-for="g in corte.gastos_detalle" :key="g.descripcion + g.fecha"
                                class="hover:bg-gray-50 dark:hover:bg-gray-700/50">
                                <td class="py-2 text-gray-800 dark:text-gray-200">{{ g.descripcion }}</td>
                                <td class="py-2 text-right font-medium text-orange-600 dark:text-orange-400 tabular-nums">{{ fmt(g.monto) }}</td>
                                <td class="py-2 text-gray-500 dark:text-gray-400">{{ g.usuario ?? '—' }}</td>
                                <td class="py-2 text-right text-gray-500 dark:text-gray-400 tabular-nums">{{ g.fecha }}</td>
                            </tr>
                        </tbody>
                        <tfoot>
                            <tr class="border-t-2 border-gray-200 dark:border-gray-600">
                                <td class="pt-2 font-semibold text-gray-700 dark:text-gray-300">Total</td>
                                <td class="pt-2 text-right font-bold text-orange-600 dark:text-orange-400 tabular-nums">{{ fmt(corte.total_gastos) }}</td>
                                <td colspan="2"></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>

            <!-- Estado vacío -->
            <div v-if="corte.count_ventas === 0 && corte.gastos_detalle.length === 0"
                 class="text-center py-16 text-gray-400 dark:text-gray-500">
                <svg class="w-12 h-12 mx-auto mb-3 opacity-40" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"
                          d="M9 17v-2m3 2v-4m3 4v-6m2 10H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                </svg>
                <p class="text-sm font-medium">Sin datos para el período seleccionado</p>
                <p class="text-xs mt-1">Cambia el rango de fechas para ver información</p>
            </div>

        </div>
    </AuthenticatedLayout>
</template>
