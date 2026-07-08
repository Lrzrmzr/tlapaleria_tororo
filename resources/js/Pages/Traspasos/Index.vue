<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';

const props = defineProps({
    traspasos: Array,
    branches: Array,
    productos: Array,
    isRoot: Boolean,
    userBranchId: [Number, String],
});

const showModal = ref(false);

const itemVacio = () => ({ product_id: '', quantity: 1 });

const form = useForm({
    branch_origen_id: props.userBranchId ?? '',
    branch_destino_id: '',
    notas: '',
    fecha: new Date().toISOString().slice(0, 10),
    items: [itemVacio()],
});

function agregarItem() {
    form.items.push(itemVacio());
}
function quitarItem(idx) {
    if (form.items.length > 1) form.items.splice(idx, 1);
}

function abrirModal() {
    form.reset();
    form.items = [itemVacio()];
    form.branch_origen_id = props.userBranchId ?? '';
    form.fecha = new Date().toISOString().slice(0, 10);
    showModal.value = true;
}

function submitForm() {
    form.post(route('traspasos.store'), {
        onSuccess: () => { showModal.value = false; },
    });
}
</script>

<template>
    <Head title="Traspasos entre sucursales" />

    <AuthenticatedLayout>
        <div class="py-6 px-4 sm:px-6 lg:px-8 max-w-5xl mx-auto">
            <div class="flex items-center justify-between mb-6">
                <div>
                    <h1 class="text-2xl font-bold text-gray-900 dark:text-white">Traspasos entre sucursales</h1>
                    <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">Mueve inventario de una sucursal a otra</p>
                </div>
                <button @click="abrirModal" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium rounded-lg">
                    Nuevo traspaso
                </button>
            </div>

            <div v-if="$page.props.flash?.success" class="mb-4 p-3 bg-green-50 dark:bg-green-900/20 border border-green-200 dark:border-green-700 text-green-700 dark:text-green-300 rounded-lg text-sm">
                {{ $page.props.flash.success }}
            </div>

            <div class="space-y-3">
                <p v-if="!traspasos.length" class="text-center text-gray-400 py-12 text-sm">No hay traspasos registrados.</p>
                <div v-for="t in traspasos" :key="t.id" class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-4">
                    <div class="flex items-center justify-between mb-2">
                        <div class="flex items-center gap-2 text-sm font-semibold text-gray-900 dark:text-white">
                            {{ t.origen }}
                            <svg class="w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 5l7 7m0 0l-7 7m7-7H3" /></svg>
                            {{ t.destino }}
                        </div>
                        <span class="text-xs text-gray-400">{{ t.fecha }}</span>
                    </div>
                    <p class="text-xs text-gray-500 dark:text-gray-400 mb-2">Por {{ t.user }}</p>
                    <ul class="text-sm text-gray-700 dark:text-gray-300 space-y-0.5">
                        <li v-for="(item, i) in t.items" :key="i">• {{ item.producto }} — {{ item.quantity }} u.</li>
                    </ul>
                    <p v-if="t.notas" class="text-xs text-gray-400 italic mt-2">{{ t.notas }}</p>
                </div>
            </div>
        </div>

        <Teleport to="body">
            <div v-if="showModal" class="fixed inset-0 z-50 flex items-start justify-center bg-black/50 overflow-y-auto py-6 px-4" @click.self="showModal = false">
                <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-2xl w-full max-w-2xl p-6">
                    <h3 class="text-lg font-bold text-gray-900 dark:text-white mb-4">Nuevo traspaso</h3>
                    <div v-if="form.errors.error" class="mb-3 p-3 bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-700 rounded-lg text-sm text-red-700 dark:text-red-300">
                        {{ form.errors.error }}
                    </div>

                    <div class="grid grid-cols-2 gap-4 mb-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Sucursal origen *</label>
                            <select v-model="form.branch_origen_id" required :disabled="!isRoot" class="w-full text-sm rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white disabled:opacity-60">
                                <option value="">Seleccionar</option>
                                <option v-for="b in branches" :key="b.id" :value="b.id">{{ b.name }}</option>
                            </select>
                            <p v-if="!isRoot" class="mt-1 text-xs text-gray-400">Solo puedes enviar desde tu propia sucursal.</p>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Sucursal destino *</label>
                            <select v-model="form.branch_destino_id" required class="w-full text-sm rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white">
                                <option value="">Seleccionar</option>
                                <option v-for="b in branches" :key="b.id" :value="b.id">{{ b.name }}</option>
                            </select>
                        </div>
                    </div>

                    <div class="mb-4">
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Fecha *</label>
                        <input type="date" v-model="form.fecha" required class="w-full text-sm rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white" />
                    </div>

                    <div class="mb-4">
                        <div class="flex items-center justify-between mb-2">
                            <label class="text-sm font-medium text-gray-700 dark:text-gray-300">Productos *</label>
                            <button type="button" @click="agregarItem" class="text-xs text-blue-600 hover:underline">+ Agregar</button>
                        </div>
                        <div class="space-y-2">
                            <div v-for="(item, idx) in form.items" :key="idx" class="grid gap-2 items-end" style="grid-template-columns: 3fr 1fr auto">
                                <select v-model="item.product_id" required class="text-xs rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white">
                                    <option value="">Producto</option>
                                    <option v-for="p in productos" :key="p.id" :value="p.id">{{ p.name }}</option>
                                </select>
                                <input type="number" v-model.number="item.quantity" min="1" required class="text-xs rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white" />
                                <button type="button" @click="quitarItem(idx)" :disabled="form.items.length <= 1" class="p-1.5 text-gray-400 hover:text-red-500 disabled:opacity-30">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" /></svg>
                                </button>
                            </div>
                        </div>
                    </div>

                    <div class="mb-4">
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Notas</label>
                        <textarea v-model="form.notas" rows="2" class="w-full text-sm rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white"></textarea>
                    </div>

                    <div class="flex justify-end gap-2">
                        <button type="button" @click="showModal = false" class="px-4 py-2 text-sm bg-gray-100 dark:bg-gray-700 rounded-lg">Cancelar</button>
                        <button @click="submitForm" :disabled="form.processing" class="px-4 py-2 text-sm bg-blue-600 hover:bg-blue-700 text-white rounded-lg disabled:opacity-50">Registrar traspaso</button>
                    </div>
                </div>
            </div>
        </Teleport>
    </AuthenticatedLayout>
</template>
