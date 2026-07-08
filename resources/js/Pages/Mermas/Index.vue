<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, useForm, router } from '@inertiajs/vue3';
import { ref } from 'vue';

const props = defineProps({
    mermas: Array,
    stats: Object,
    productos: Array,
    branches: Array,
    isRoot: Boolean,
    filters: Object,
});

const showModal = ref(false);
const branchId = ref(props.filters?.branch_id ?? '');

function filtrar() {
    router.get(route('mermas.index'), { branch_id: branchId.value }, { preserveState: true });
}

const motivos = [
    { value: 'dañado', label: 'Dañado' },
    { value: 'caducado', label: 'Caducado' },
    { value: 'robo', label: 'Robo' },
    { value: 'extravio', label: 'Extravío' },
    { value: 'otro', label: 'Otro' },
];

const form = useForm({
    branch_id: '',
    product_id: '',
    quantity: 1,
    motivo: 'dañado',
    notas: '',
    fecha: new Date().toISOString().slice(0, 10),
});

function abrirModal() {
    form.reset();
    form.fecha = new Date().toISOString().slice(0, 10);
    showModal.value = true;
}

function submitForm() {
    form.post(route('mermas.store'), {
        onSuccess: () => { showModal.value = false; },
    });
}

function eliminar(id) {
    if (confirm('¿Eliminar este registro de merma? El stock no se revertirá automáticamente.')) {
        router.delete(route('mermas.destroy', id));
    }
}
</script>

<template>
    <Head title="Mermas" />

    <AuthenticatedLayout>
        <div class="py-6 px-4 sm:px-6 lg:px-8 max-w-5xl mx-auto">
            <div class="flex items-center justify-between mb-6">
                <div>
                    <h1 class="text-2xl font-bold text-gray-900 dark:text-white">Mermas</h1>
                    <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">Productos dañados, caducados, robados o extraviados</p>
                </div>
                <button @click="abrirModal" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium rounded-lg">
                    Registrar merma
                </button>
            </div>

            <div v-if="$page.props.flash?.success" class="mb-4 p-3 bg-green-50 dark:bg-green-900/20 border border-green-200 dark:border-green-700 text-green-700 dark:text-green-300 rounded-lg text-sm">
                {{ $page.props.flash.success }}
            </div>
            <div v-if="$page.props.errors?.error" class="mb-4 p-3 bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-700 text-red-700 dark:text-red-300 rounded-lg text-sm">
                {{ $page.props.errors.error }}
            </div>

            <div class="grid grid-cols-3 gap-4 mb-6">
                <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-4">
                    <p class="text-xs text-gray-500 dark:text-gray-400 uppercase">Registros</p>
                    <p class="text-2xl font-bold text-gray-900 dark:text-white">{{ stats.total }}</p>
                </div>
                <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-4">
                    <p class="text-xs text-gray-500 dark:text-gray-400 uppercase">Unidades perdidas</p>
                    <p class="text-2xl font-bold text-red-600 dark:text-red-400">{{ stats.unidades }}</p>
                </div>
                <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-4">
                    <p class="text-xs text-gray-500 dark:text-gray-400 uppercase">Este mes</p>
                    <p class="text-2xl font-bold text-gray-900 dark:text-white">{{ stats.este_mes }}</p>
                </div>
            </div>

            <div v-if="isRoot" class="mb-4">
                <select v-model="branchId" @change="filtrar" class="text-sm rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white">
                    <option value="">Todas las sucursales</option>
                    <option v-for="b in branches" :key="b.id" :value="b.id">{{ b.name }}</option>
                </select>
            </div>

            <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 overflow-hidden">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-xs text-gray-500 dark:text-gray-400 border-b border-gray-100 dark:border-gray-700">
                            <th class="text-left p-3">Producto</th>
                            <th class="text-center p-3">Cant.</th>
                            <th class="text-left p-3">Motivo</th>
                            <th class="text-left p-3">Sucursal</th>
                            <th class="text-left p-3">Fecha</th>
                            <th class="text-left p-3">Registró</th>
                            <th class="p-3"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-if="!mermas.length">
                            <td colspan="7" class="text-center text-gray-400 py-8 text-sm">Sin mermas registradas.</td>
                        </tr>
                        <tr v-for="m in mermas" :key="m.id" class="border-b border-gray-50 dark:border-gray-700/50 last:border-0">
                            <td class="p-3 text-gray-800 dark:text-gray-200">{{ m.producto }}</td>
                            <td class="p-3 text-center text-gray-700 dark:text-gray-300">{{ m.quantity }}</td>
                            <td class="p-3 capitalize text-gray-700 dark:text-gray-300">{{ m.motivo }}</td>
                            <td class="p-3 text-gray-600 dark:text-gray-400">{{ m.branch }}</td>
                            <td class="p-3 text-gray-600 dark:text-gray-400">{{ m.fecha }}</td>
                            <td class="p-3 text-gray-600 dark:text-gray-400">{{ m.user }}</td>
                            <td class="p-3 text-right">
                                <button @click="eliminar(m.id)" class="text-xs text-red-500 hover:underline">Eliminar</button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <Teleport to="body">
            <div v-if="showModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50" @click.self="showModal = false">
                <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-2xl w-full max-w-md p-6">
                    <h3 class="text-lg font-bold text-gray-900 dark:text-white mb-4">Registrar merma</h3>

                    <div v-if="isRoot" class="mb-3">
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Sucursal *</label>
                        <select v-model="form.branch_id" required class="w-full text-sm rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white">
                            <option value="">Seleccionar</option>
                            <option v-for="b in branches" :key="b.id" :value="b.id">{{ b.name }}</option>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Producto *</label>
                        <select v-model="form.product_id" required class="w-full text-sm rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white">
                            <option value="">Seleccionar</option>
                            <option v-for="p in productos" :key="p.id" :value="p.id">{{ p.name }}</option>
                        </select>
                    </div>

                    <div class="grid grid-cols-2 gap-3 mb-3">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Cantidad *</label>
                            <input type="number" v-model.number="form.quantity" min="1" required class="w-full text-sm rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white" />
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Motivo *</label>
                            <select v-model="form.motivo" required class="w-full text-sm rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white">
                                <option v-for="m in motivos" :key="m.value" :value="m.value">{{ m.label }}</option>
                            </select>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Fecha *</label>
                        <input type="date" v-model="form.fecha" required class="w-full text-sm rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white" />
                    </div>

                    <div class="mb-4">
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Notas</label>
                        <textarea v-model="form.notas" rows="2" class="w-full text-sm rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white"></textarea>
                    </div>

                    <div class="flex justify-end gap-2">
                        <button type="button" @click="showModal = false" class="px-4 py-2 text-sm bg-gray-100 dark:bg-gray-700 rounded-lg">Cancelar</button>
                        <button @click="submitForm" :disabled="form.processing" class="px-4 py-2 text-sm bg-blue-600 hover:bg-blue-700 text-white rounded-lg disabled:opacity-50">Registrar</button>
                    </div>
                </div>
            </div>
        </Teleport>
    </AuthenticatedLayout>
</template>
