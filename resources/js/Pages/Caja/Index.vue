<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, useForm, router } from '@inertiajs/vue3';
import { ref } from 'vue';

const props = defineProps({
    cajaAbierta: Object,
    historial: Array,
    branches: Array,
    isRoot: Boolean,
    filters: Object,
});

const branchId = ref(props.filters?.branch_id ?? '');

function filtrar() {
    router.get(route('caja.index'), { branch_id: branchId.value }, { preserveState: true });
}

const fmt = (n) => new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' }).format(n ?? 0);

const showAbrir = ref(false);
const showCerrar = ref(false);

const formAbrir = useForm({ fondo_inicial: 0, branch_id: '' });
const formCerrar = useForm({ efectivo_contado: 0, notas: '' });

function openAbrirModal() {
    // Preseleccionar la sucursal que root ya tiene filtrada, si eligió una.
    formAbrir.branch_id = props.isRoot ? (branchId.value || '') : '';
    showAbrir.value = true;
}

function abrirCaja() {
    formAbrir.post(route('caja.abrir'), {
        onSuccess: () => { showAbrir.value = false; formAbrir.reset(); },
    });
}

function cerrarCaja() {
    formCerrar.post(route('caja.cerrar', props.cajaAbierta.id), {
        onSuccess: () => { showCerrar.value = false; formCerrar.reset(); },
    });
}
</script>

<template>
    <Head title="Caja" />

    <AuthenticatedLayout>
        <div class="py-6 px-4 sm:px-6 lg:px-8 max-w-5xl mx-auto">
            <div class="flex items-center justify-between mb-6">
                <div>
                    <h1 class="text-2xl font-bold text-gray-900 dark:text-white">Caja</h1>
                    <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">Apertura y cierre de turno, con conteo de efectivo</p>
                </div>
            </div>

            <div v-if="$page.props.flash?.success" class="mb-4 p-3 bg-green-50 dark:bg-green-900/20 border border-green-200 dark:border-green-700 text-green-700 dark:text-green-300 rounded-lg text-sm">
                {{ $page.props.flash.success }}
            </div>
            <div v-if="$page.props.errors?.error" class="mb-4 p-3 bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-700 text-red-700 dark:text-red-300 rounded-lg text-sm">
                {{ $page.props.errors.error }}
            </div>

            <div v-if="isRoot" class="mb-6 flex items-center gap-2">
                <select v-model="branchId" @change="filtrar" class="text-sm rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white">
                    <option value="">Todas las sucursales</option>
                    <option v-for="b in branches" :key="b.id" :value="b.id">{{ b.name }}</option>
                </select>
            </div>

            <!-- Caja abierta -->
            <div v-if="cajaAbierta" class="bg-white dark:bg-gray-800 rounded-xl border border-green-300 dark:border-green-700 p-6 mb-6">
                <div class="flex items-center justify-between mb-4">
                    <div>
                        <span class="px-2.5 py-0.5 rounded-full text-xs font-semibold bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-300">Turno abierto</span>
                        <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">
                            {{ cajaAbierta.branch?.name }} · Abrió {{ cajaAbierta.user }} · {{ cajaAbierta.fecha_apertura }}
                        </p>
                    </div>
                    <button @click="showCerrar = true" class="px-4 py-2 bg-red-600 hover:bg-red-700 text-white text-sm font-medium rounded-lg">
                        Cerrar turno
                    </button>
                </div>
                <p class="text-sm text-gray-600 dark:text-gray-400">Fondo inicial: <strong>{{ fmt(cajaAbierta.fondo_inicial) }}</strong></p>
            </div>

            <div v-else class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-6 mb-6 text-center">
                <p class="text-gray-500 dark:text-gray-400 mb-4">No hay un turno de caja abierto.</p>
                <button @click="openAbrirModal" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium rounded-lg">
                    Abrir turno
                </button>
            </div>

            <!-- Historial -->
            <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 overflow-hidden">
                <div class="p-4 border-b border-gray-100 dark:border-gray-700">
                    <h2 class="font-semibold text-gray-900 dark:text-white">Historial de cortes</h2>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="text-xs text-gray-500 dark:text-gray-400 border-b border-gray-100 dark:border-gray-700">
                                <th class="text-left p-3">Sucursal</th>
                                <th class="text-left p-3">Apertura</th>
                                <th class="text-left p-3">Cierre</th>
                                <th class="text-right p-3">Esperado</th>
                                <th class="text-right p-3">Contado</th>
                                <th class="text-right p-3">Diferencia</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-if="!historial.length">
                                <td colspan="6" class="text-center text-gray-400 py-8 text-sm">Sin cortes registrados aún.</td>
                            </tr>
                            <tr v-for="c in historial" :key="c.id" class="border-b border-gray-50 dark:border-gray-700/50 last:border-0">
                                <td class="p-3 text-gray-800 dark:text-gray-200">{{ c.branch?.name }}</td>
                                <td class="p-3 text-gray-600 dark:text-gray-400">{{ c.fecha_apertura }}</td>
                                <td class="p-3 text-gray-600 dark:text-gray-400">{{ c.fecha_cierre }}</td>
                                <td class="p-3 text-right text-gray-800 dark:text-gray-200">{{ fmt(c.efectivo_esperado) }}</td>
                                <td class="p-3 text-right text-gray-800 dark:text-gray-200">{{ fmt(c.efectivo_contado) }}</td>
                                <td class="p-3 text-right font-semibold" :class="Math.abs(c.diferencia) < 0.01 ? 'text-gray-500' : (c.diferencia > 0 ? 'text-green-600' : 'text-red-600')">
                                    {{ c.diferencia > 0 ? '+' : '' }}{{ fmt(c.diferencia) }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Modal abrir -->
        <Teleport to="body">
            <div v-if="showAbrir" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50" @click.self="showAbrir = false">
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-xl w-full max-w-sm p-6">
                    <h3 class="text-lg font-bold text-gray-900 dark:text-white mb-4">Abrir turno de caja</h3>
                    <template v-if="isRoot">
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Sucursal</label>
                        <select v-model="formAbrir.branch_id" class="w-full text-sm rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white mb-4">
                            <option value="" disabled>Selecciona una sucursal</option>
                            <option v-for="b in branches" :key="b.id" :value="b.id">{{ b.name }}</option>
                        </select>
                    </template>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Fondo inicial</label>
                    <input v-model.number="formAbrir.fondo_inicial" type="number" min="0" step="0.01" class="w-full text-sm rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white mb-4" />
                    <div class="flex justify-end gap-2">
                        <button @click="showAbrir = false" class="px-4 py-2 text-sm bg-gray-100 dark:bg-gray-700 rounded-lg">Cancelar</button>
                        <button @click="abrirCaja" :disabled="formAbrir.processing || (isRoot && !formAbrir.branch_id)" class="px-4 py-2 text-sm bg-blue-600 hover:bg-blue-700 text-white rounded-lg disabled:opacity-50">Abrir</button>
                    </div>
                </div>
            </div>
        </Teleport>

        <!-- Modal cerrar -->
        <Teleport to="body">
            <div v-if="showCerrar" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50" @click.self="showCerrar = false">
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-xl w-full max-w-sm p-6">
                    <h3 class="text-lg font-bold text-gray-900 dark:text-white mb-4">Cerrar turno de caja</h3>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Efectivo contado físicamente</label>
                    <input v-model.number="formCerrar.efectivo_contado" type="number" min="0" step="0.01" class="w-full text-sm rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white mb-3" />
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Notas (opcional)</label>
                    <textarea v-model="formCerrar.notas" rows="2" class="w-full text-sm rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white mb-4"></textarea>
                    <div class="flex justify-end gap-2">
                        <button @click="showCerrar = false" class="px-4 py-2 text-sm bg-gray-100 dark:bg-gray-700 rounded-lg">Cancelar</button>
                        <button @click="cerrarCaja" :disabled="formCerrar.processing" class="px-4 py-2 text-sm bg-red-600 hover:bg-red-700 text-white rounded-lg disabled:opacity-50">Cerrar turno</button>
                    </div>
                </div>
            </div>
        </Teleport>
    </AuthenticatedLayout>
</template>
