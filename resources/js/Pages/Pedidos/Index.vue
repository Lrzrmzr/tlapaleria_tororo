<script setup>
import { ref, computed, watch } from 'vue';
import { router, useForm, Head } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import axios from 'axios';

const props = defineProps({
    pedidos:                  Array,
    stats:                    Object,
    suppliers:                Array,
    branches:                 Array,
    productos:                Array,
    comprasLibresHabilitadas: Boolean,
    filters:                  Object,
    user:                     Object,
    groqConfigurado:          Boolean,
});

// ── UI State ──────────────────────────────────────────────────────────────────
const mostrarFormulario = ref(false);
const tabActiva         = ref('manual');   // 'manual' | 'ia'
const pedidoExpandido   = ref(null);
const flash             = ref('');

// ── Filtros ───────────────────────────────────────────────────────────────────
const filtros = ref({
    supplier_id: props.filters?.supplier_id ?? '',
    tipo_pago:   props.filters?.tipo_pago   ?? '',
    branch_id:   props.filters?.branch_id   ?? '',
    fecha_desde: props.filters?.fecha_desde ?? '',
    fecha_hasta: props.filters?.fecha_hasta ?? '',
});

function aplicarFiltros() {
    router.get(route('pedidos.index'), filtros.value, { preserveState: true, replace: true });
}

function limpiarFiltros() {
    filtros.value = { supplier_id: '', tipo_pago: '', branch_id: '', fecha_desde: '', fecha_hasta: '' };
    router.get(route('pedidos.index'));
}

// ── Form ──────────────────────────────────────────────────────────────────────
const itemVacio = () => ({
    tipo_item:   'catalogo',
    product_id:  '',
    descripcion: '',
    quantity:    1,
    cost:        0,
    subtotal:    0,
});

const form = useForm({
    supplier_id:       '',
    branch_id:         props.user?.branchId ?? (props.branches?.[0]?.id ?? ''),
    tipo_pago:         'contado',
    numero_factura:    '',
    numero_pedido:     '',
    fecha_factura:     new Date().toISOString().slice(0, 10),
    fecha_vencimiento: '',
    notas:             '',
    items:             [itemVacio()],
});

const esCredito = computed(() => form.tipo_pago === 'credito');

const totalForm = computed(() =>
    form.items.reduce((s, i) => s + (parseFloat(i.quantity) || 0) * (parseFloat(i.cost) || 0), 0)
);

function agregarItem(tipo = 'catalogo') {
    form.items.push({ ...itemVacio(), tipo_item: tipo });
}

function quitarItem(idx) {
    if (form.items.length > 1) form.items.splice(idx, 1);
}

function recalcular(item) {
    item.subtotal = parseFloat(((parseFloat(item.quantity) || 0) * (parseFloat(item.cost) || 0)).toFixed(2));
}

function onProductoSeleccionado(item) {
    const prod = props.productos?.find(p => p.id == item.product_id);
    if (prod && !item.cost) item.cost = prod.cost;
    recalcular(item);
}

function abrirFormulario() {
    form.reset();
    form.items             = [itemVacio()];
    form.branch_id         = props.user?.branchId ?? (props.branches?.[0]?.id ?? '');
    form.fecha_factura     = new Date().toISOString().slice(0, 10);
    tabActiva.value        = 'manual';
    mostrarFormulario.value = true;
    iaReset();
}

function cerrarFormulario() {
    mostrarFormulario.value = false;
}

function submitForm() {
    form
        .transform((data) => ({ ...data, imagen: archivoIA.value }))
        .post(route('pedidos.store'), {
            onSuccess: () => {
                cerrarFormulario();
            },
        });
}

// ── IA ────────────────────────────────────────────────────────────────────────
const archivoIA      = ref(null);
const archivoPreview = ref(null);
const parseando      = ref(false);
const resultadoIA    = ref(null);
const errorIA        = ref('');
const inputArchivoRef = ref(null);

function iaReset() {
    archivoIA.value      = null;
    archivoPreview.value = null;
    resultadoIA.value    = null;
    errorIA.value        = '';
    parseando.value      = false;
}

function onArchivoSeleccionado(e) {
    const file = e.target.files?.[0];
    if (!file) return;
    archivoIA.value      = file;
    archivoPreview.value = URL.createObjectURL(file);
    resultadoIA.value    = null;
    errorIA.value        = '';
}

function onDropArchivo(e) {
    e.preventDefault();
    const file = e.dataTransfer?.files?.[0];
    if (!file) return;
    archivoIA.value      = file;
    archivoPreview.value = URL.createObjectURL(file);
    resultadoIA.value    = null;
    errorIA.value        = '';
}

async function analizarDocumento() {
    if (!archivoIA.value) return;
    parseando.value   = true;
    errorIA.value     = '';
    resultadoIA.value = null;

    const fd = new FormData();
    fd.append('archivo', archivoIA.value);

    try {
        const res = await axios.post(route('pedidos.parse'), fd, {
            headers: { 'Content-Type': 'multipart/form-data' },
        });
        resultadoIA.value = res.data.data;
    } catch (err) {
        errorIA.value = err.response?.data?.error ?? 'Error al analizar el documento.';
    } finally {
        parseando.value = false;
    }
}

function aplicarResultadoIA() {
    const d = resultadoIA.value;
    if (!d) return;

    if (d.numero_factura) form.numero_factura = d.numero_factura;
    if (d.numero_pedido)  form.numero_pedido  = d.numero_pedido;
    if (d.fecha)          form.fecha_factura  = d.fecha;

    const items = (d.items ?? []).map(it => {
        const desc  = it.descripcion ?? '';
        const lower = desc.toLowerCase();
        const prod  = props.productos?.find(p =>
            p.name.toLowerCase().includes(lower) || lower.includes(p.name.toLowerCase())
        );
        return {
            tipo_item:   prod ? 'catalogo' : 'libre',
            product_id:  prod?.id ?? '',
            descripcion: desc,
            quantity:    Math.max(1, Math.round(parseFloat(it.cantidad) || 1)),
            cost:        parseFloat(it.precio_unitario) || 0,
            subtotal:    parseFloat(it.subtotal) || 0,
        };
    });

    if (items.length) form.items = items;
    tabActiva.value = 'manual';
}

// ── Helpers vista ─────────────────────────────────────────────────────────────
const fmt = (n) => new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' }).format(n ?? 0);

function toggleExpansion(id) {
    pedidoExpandido.value = pedidoExpandido.value === id ? null : id;
}
</script>

<template>
    <Head title="Pedidos recibidos" />

    <AuthenticatedLayout>
        <div class="py-6 px-4 sm:px-6 lg:px-8 max-w-7xl mx-auto">

            <!-- Header -->
            <div class="flex items-center justify-between mb-6">
                <div>
                    <h1 class="text-2xl font-bold text-gray-900 dark:text-white">Pedidos recibidos</h1>
                    <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">Registro de mercancía recibida de proveedores</p>
                </div>
                <button
                    @click="abrirFormulario"
                    class="inline-flex items-center gap-2 px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium rounded-lg transition-colors"
                >
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
                    </svg>
                    Registrar pedido
                </button>
            </div>

            <!-- Flash message -->
            <div v-if="$page.props.flash?.success" class="mb-4 p-3 bg-green-50 dark:bg-green-900/20 border border-green-200 dark:border-green-700 text-green-700 dark:text-green-300 rounded-lg text-sm">
                {{ $page.props.flash.success }}
            </div>

            <!-- Stats -->
            <div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
                <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-4">
                    <p class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wide">Total pedidos</p>
                    <p class="text-2xl font-bold text-gray-900 dark:text-white mt-1">{{ stats.total_pedidos }}</p>
                </div>
                <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-4">
                    <p class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wide">Monto total</p>
                    <p class="text-2xl font-bold text-blue-600 dark:text-blue-400 mt-1">{{ fmt(stats.total_monto) }}</p>
                </div>
                <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-4">
                    <p class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wide">A crédito</p>
                    <p class="text-2xl font-bold text-orange-600 dark:text-orange-400 mt-1">{{ stats.pedidos_credito }}</p>
                </div>
                <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-4">
                    <p class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wide">Hoy</p>
                    <p class="text-2xl font-bold text-green-600 dark:text-green-400 mt-1">{{ stats.pedidos_hoy }}</p>
                </div>
            </div>

            <!-- Filtros -->
            <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-4 mb-6">
                <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-5 gap-3">
                    <select v-model="filtros.supplier_id" class="col-span-1 text-sm rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white">
                        <option value="">Todos los proveedores</option>
                        <option v-for="s in suppliers" :key="s.id" :value="s.id">{{ s.name }}</option>
                    </select>
                    <select v-model="filtros.tipo_pago" class="text-sm rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white">
                        <option value="">Tipo de pago</option>
                        <option value="contado">Contado</option>
                        <option value="credito">Crédito</option>
                    </select>
                    <select v-if="user.isRoot" v-model="filtros.branch_id" class="text-sm rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white">
                        <option value="">Todas las sucursales</option>
                        <option v-for="b in branches" :key="b.id" :value="b.id">{{ b.name }}</option>
                    </select>
                    <input type="date" v-model="filtros.fecha_desde" class="text-sm rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white" placeholder="Desde" />
                    <input type="date" v-model="filtros.fecha_hasta" class="text-sm rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white" placeholder="Hasta" />
                </div>
                <div class="flex gap-2 mt-3">
                    <button @click="aplicarFiltros" class="px-3 py-1.5 text-xs bg-blue-600 hover:bg-blue-700 text-white rounded-lg">Filtrar</button>
                    <button @click="limpiarFiltros" class="px-3 py-1.5 text-xs bg-gray-100 hover:bg-gray-200 dark:bg-gray-700 dark:hover:bg-gray-600 text-gray-700 dark:text-gray-300 rounded-lg">Limpiar</button>
                </div>
            </div>

            <!-- Lista de pedidos -->
            <div class="space-y-3">
                <p v-if="!pedidos.length" class="text-center text-gray-400 dark:text-gray-500 py-12 text-sm">
                    No hay pedidos registrados. Usa el botón "Registrar pedido" para comenzar.
                </p>

                <div
                    v-for="pedido in pedidos"
                    :key="pedido.id"
                    class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 overflow-hidden"
                >
                    <!-- Cabecera del pedido -->
                    <div
                        class="flex items-center justify-between p-4 cursor-pointer hover:bg-gray-50 dark:hover:bg-gray-700/50 transition-colors"
                        @click="toggleExpansion(pedido.id)"
                    >
                        <div class="flex items-center gap-3 min-w-0">
                            <div class="flex-shrink-0">
                                <span
                                    :class="pedido.tipo_pago === 'credito'
                                        ? 'bg-orange-100 text-orange-700 dark:bg-orange-900/30 dark:text-orange-300'
                                        : 'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-300'"
                                    class="px-2.5 py-0.5 rounded-full text-xs font-semibold uppercase"
                                >
                                    {{ pedido.tipo_pago }}
                                </span>
                            </div>
                            <div class="min-w-0">
                                <p class="font-semibold text-gray-900 dark:text-white text-sm truncate">
                                    {{ pedido.supplier?.name ?? '—' }}
                                </p>
                                <p class="text-xs text-gray-500 dark:text-gray-400">
                                    {{ pedido.created_at }}
                                    <span v-if="pedido.numero_factura"> · Fact. {{ pedido.numero_factura }}</span>
                                    <span v-if="pedido.branch"> · {{ pedido.branch.name }}</span>
                                </p>
                            </div>
                        </div>
                        <div class="flex items-center gap-4 ml-4">
                            <div class="text-right">
                                <p class="font-bold text-gray-900 dark:text-white text-sm">{{ fmt(pedido.total) }}</p>
                                <p class="text-xs text-gray-400">{{ pedido.items.length }} artículo{{ pedido.items.length !== 1 ? 's' : '' }}</p>
                            </div>
                            <svg
                                class="w-4 h-4 text-gray-400 transition-transform"
                                :class="{ 'rotate-180': pedidoExpandido === pedido.id }"
                                fill="none" stroke="currentColor" viewBox="0 0 24 24"
                            >
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
                            </svg>
                        </div>
                    </div>

                    <!-- Detalle expandido -->
                    <div v-if="pedidoExpandido === pedido.id" class="border-t border-gray-100 dark:border-gray-700 p-4">
                        <div class="grid grid-cols-2 sm:grid-cols-3 gap-4 mb-4 text-sm">
                            <div v-if="pedido.numero_pedido">
                                <p class="text-xs text-gray-500 dark:text-gray-400">N° pedido</p>
                                <p class="font-medium text-gray-800 dark:text-gray-200">{{ pedido.numero_pedido }}</p>
                            </div>
                            <div v-if="pedido.fecha_factura">
                                <p class="text-xs text-gray-500 dark:text-gray-400">Fecha nota</p>
                                <p class="font-medium text-gray-800 dark:text-gray-200">{{ pedido.fecha_factura }}</p>
                            </div>
                            <div v-if="pedido.fecha_vencimiento">
                                <p class="text-xs text-gray-500 dark:text-gray-400">Vencimiento</p>
                                <p class="font-medium text-orange-600 dark:text-orange-400">{{ pedido.fecha_vencimiento }}</p>
                            </div>
                            <div v-if="pedido.user">
                                <p class="text-xs text-gray-500 dark:text-gray-400">Registrado por</p>
                                <p class="font-medium text-gray-800 dark:text-gray-200">{{ pedido.user.name }}</p>
                            </div>
                        </div>

                        <table class="w-full text-sm">
                            <thead>
                                <tr class="text-xs text-gray-500 dark:text-gray-400 border-b border-gray-100 dark:border-gray-700">
                                    <th class="text-left pb-2 font-medium">Artículo</th>
                                    <th class="text-center pb-2 font-medium w-20">Cant.</th>
                                    <th class="text-right pb-2 font-medium w-28">Costo unit.</th>
                                    <th class="text-right pb-2 font-medium w-28">Subtotal</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr
                                    v-for="(item, i) in pedido.items"
                                    :key="i"
                                    class="border-b border-gray-50 dark:border-gray-700/50 last:border-0"
                                >
                                    <td class="py-1.5 text-gray-800 dark:text-gray-200">{{ item.descripcion }}</td>
                                    <td class="py-1.5 text-center text-gray-700 dark:text-gray-300">{{ item.quantity }}</td>
                                    <td class="py-1.5 text-right text-gray-700 dark:text-gray-300">{{ fmt(item.cost) }}</td>
                                    <td class="py-1.5 text-right font-medium text-gray-900 dark:text-white">{{ fmt(item.subtotal) }}</td>
                                </tr>
                            </tbody>
                            <tfoot>
                                <tr>
                                    <td colspan="3" class="pt-3 text-right text-sm font-semibold text-gray-700 dark:text-gray-300">Total</td>
                                    <td class="pt-3 text-right text-sm font-bold text-gray-900 dark:text-white">{{ fmt(pedido.total) }}</td>
                                </tr>
                            </tfoot>
                        </table>

                        <p v-if="pedido.notas" class="mt-3 text-xs text-gray-500 dark:text-gray-400 italic">
                            Notas: {{ pedido.notas }}
                        </p>

                        <a
                            v-if="pedido.imagen_url"
                            :href="pedido.imagen_url"
                            target="_blank"
                            rel="noopener"
                            class="mt-3 inline-flex items-center gap-1.5 text-xs text-blue-600 dark:text-blue-400 hover:underline"
                        >
                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z" />
                            </svg>
                            Ver foto de la nota original
                        </a>
                    </div>
                </div>
            </div>
        </div>

        <!-- ── MODAL NUEVO PEDIDO ─────────────────────────────────────────── -->
        <Teleport to="body">
            <div
                v-if="mostrarFormulario"
                class="fixed inset-0 z-50 flex items-start justify-center bg-black/50 overflow-y-auto py-6 px-4"
                @click.self="cerrarFormulario"
            >
                <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-2xl w-full max-w-3xl">
                    <!-- Modal header -->
                    <div class="flex items-center justify-between px-6 py-4 border-b border-gray-200 dark:border-gray-700">
                        <h2 class="text-lg font-bold text-gray-900 dark:text-white">Registrar pedido recibido</h2>
                        <button @click="cerrarFormulario" class="p-1 rounded-lg hover:bg-gray-100 dark:hover:bg-gray-700 text-gray-500">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                            </svg>
                        </button>
                    </div>

                    <!-- Tabs -->
                    <div class="flex border-b border-gray-200 dark:border-gray-700 px-6">
                        <button
                            @click="tabActiva = 'manual'"
                            :class="tabActiva === 'manual'
                                ? 'border-b-2 border-blue-600 text-blue-600 dark:text-blue-400'
                                : 'text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-200'"
                            class="py-3 px-1 mr-6 text-sm font-medium transition-colors"
                        >
                            Captura manual
                        </button>
                        <button
                            @click="tabActiva = 'ia'"
                            :class="tabActiva === 'ia'
                                ? 'border-b-2 border-blue-600 text-blue-600 dark:text-blue-400'
                                : 'text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-200'"
                            class="py-3 px-1 text-sm font-medium flex items-center gap-1.5 transition-colors"
                        >
                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z" />
                            </svg>
                            Escanear con IA
                        </button>
                    </div>

                    <!-- ── TAB IA ───────────────────────────────────────────── -->
                    <div v-if="tabActiva === 'ia'" class="p-6 space-y-4">
                        <div v-if="!groqConfigurado" class="p-4 bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-700 rounded-lg text-sm text-amber-700 dark:text-amber-300">
                            <strong>IA no configurada.</strong> Agrega <code class="bg-amber-100 dark:bg-amber-800 px-1 rounded">GROQ_API_KEY</code> en el archivo <code class="bg-amber-100 dark:bg-amber-800 px-1 rounded">.env</code> para activar el análisis automático.
                        </div>

                        <!-- Drop zone -->
                        <div
                            v-if="!archivoPreview"
                            class="border-2 border-dashed border-gray-300 dark:border-gray-600 rounded-xl p-10 text-center cursor-pointer hover:border-blue-400 dark:hover:border-blue-500 transition-colors"
                            @click="inputArchivoRef?.click()"
                            @dragover.prevent
                            @drop="onDropArchivo"
                        >
                            <svg class="w-10 h-10 mx-auto text-gray-300 dark:text-gray-600 mb-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z" />
                            </svg>
                            <p class="text-sm text-gray-500 dark:text-gray-400">Arrastra una foto de la nota o haz clic para seleccionar</p>
                            <p class="text-xs text-gray-400 dark:text-gray-500 mt-1">JPG, PNG, WEBP — máx 10 MB</p>
                        </div>

                        <!-- Preview -->
                        <div v-if="archivoPreview" class="relative">
                            <img :src="archivoPreview" alt="Nota del proveedor" class="w-full max-h-64 object-contain rounded-xl border border-gray-200 dark:border-gray-700" />
                            <button
                                @click="iaReset(); inputArchivoRef && (inputArchivoRef.value = '')"
                                class="absolute top-2 right-2 p-1 bg-white dark:bg-gray-700 rounded-full shadow text-gray-500 hover:text-red-500"
                            >
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                                </svg>
                            </button>
                        </div>

                        <input
                            ref="inputArchivoRef"
                            type="file"
                            accept="image/jpeg,image/png,image/webp"
                            class="hidden"
                            @change="onArchivoSeleccionado"
                        />

                        <!-- Analyze button -->
                        <button
                            v-if="archivoPreview && !resultadoIA"
                            :disabled="parseando || !groqConfigurado"
                            @click="analizarDocumento"
                            class="w-full py-2.5 bg-blue-600 hover:bg-blue-700 disabled:opacity-50 text-white text-sm font-medium rounded-lg transition-colors flex items-center justify-center gap-2"
                        >
                            <svg v-if="!parseando" class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z" />
                            </svg>
                            <svg v-else class="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z" />
                            </svg>
                            {{ parseando ? 'Analizando nota...' : 'Analizar con IA' }}
                        </button>

                        <!-- Error -->
                        <p v-if="errorIA" class="text-sm text-red-600 dark:text-red-400 bg-red-50 dark:bg-red-900/20 rounded-lg px-3 py-2">{{ errorIA }}</p>

                        <!-- Resultado -->
                        <div v-if="resultadoIA" class="bg-gray-50 dark:bg-gray-700/50 rounded-xl p-4 space-y-2 text-sm">
                            <p class="font-semibold text-gray-700 dark:text-gray-300 text-xs uppercase tracking-wide mb-3">Datos extraídos</p>
                            <div class="grid grid-cols-2 gap-2 text-xs">
                                <div v-if="resultadoIA.proveedor_nombre">
                                    <span class="text-gray-500 dark:text-gray-400">Proveedor:</span>
                                    <span class="ml-1 text-gray-800 dark:text-gray-200 font-medium">{{ resultadoIA.proveedor_nombre }}</span>
                                </div>
                                <div v-if="resultadoIA.numero_factura">
                                    <span class="text-gray-500 dark:text-gray-400">Factura/nota:</span>
                                    <span class="ml-1 text-gray-800 dark:text-gray-200 font-medium">{{ resultadoIA.numero_factura }}</span>
                                </div>
                                <div v-if="resultadoIA.fecha">
                                    <span class="text-gray-500 dark:text-gray-400">Fecha:</span>
                                    <span class="ml-1 text-gray-800 dark:text-gray-200 font-medium">{{ resultadoIA.fecha }}</span>
                                </div>
                                <div v-if="resultadoIA.total">
                                    <span class="text-gray-500 dark:text-gray-400">Total:</span>
                                    <span class="ml-1 font-bold text-gray-900 dark:text-white">{{ fmt(resultadoIA.total) }}</span>
                                </div>
                            </div>
                            <div v-if="resultadoIA.items?.length" class="mt-2">
                                <p class="text-xs text-gray-500 dark:text-gray-400 mb-1">{{ resultadoIA.items.length }} artículo(s) detectado(s)</p>
                                <ul class="text-xs space-y-0.5 text-gray-700 dark:text-gray-300">
                                    <li v-for="(it, i) in resultadoIA.items.slice(0, 5)" :key="i">
                                        • {{ it.descripcion }} — {{ it.cantidad }} × {{ fmt(it.precio_unitario) }}
                                    </li>
                                    <li v-if="resultadoIA.items.length > 5" class="text-gray-400 dark:text-gray-500">
                                        ... y {{ resultadoIA.items.length - 5 }} más
                                    </li>
                                </ul>
                            </div>
                            <button
                                @click="aplicarResultadoIA"
                                class="mt-3 w-full py-2 bg-green-600 hover:bg-green-700 text-white text-sm font-medium rounded-lg transition-colors"
                            >
                                Aplicar al formulario
                            </button>
                        </div>
                    </div>

                    <!-- ── TAB MANUAL ───────────────────────────────────────── -->
                    <form v-if="tabActiva === 'manual'" @submit.prevent="submitForm" class="p-6 space-y-5">
                        <!-- Error general -->
                        <div v-if="form.errors.error" class="p-3 bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-700 rounded-lg text-sm text-red-700 dark:text-red-300">
                            {{ form.errors.error }}
                        </div>

                        <!-- Fila 1: proveedor + sucursal -->
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Proveedor *</label>
                                <select v-model="form.supplier_id" required class="w-full text-sm rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white">
                                    <option value="">Seleccionar proveedor</option>
                                    <option v-for="s in suppliers" :key="s.id" :value="s.id">{{ s.name }}</option>
                                </select>
                                <p v-if="form.errors.supplier_id" class="mt-1 text-xs text-red-500">{{ form.errors.supplier_id }}</p>
                            </div>
                            <div v-if="user.isRoot">
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Sucursal *</label>
                                <select v-model="form.branch_id" required class="w-full text-sm rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white">
                                    <option value="">Seleccionar sucursal</option>
                                    <option v-for="b in branches" :key="b.id" :value="b.id">{{ b.name }}</option>
                                </select>
                                <p v-if="form.errors.branch_id" class="mt-1 text-xs text-red-500">{{ form.errors.branch_id }}</p>
                            </div>
                        </div>

                        <!-- Fila 2: tipo de pago + fecha -->
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Tipo de pago *</label>
                                <div class="flex rounded-lg overflow-hidden border border-gray-300 dark:border-gray-600">
                                    <button
                                        type="button"
                                        @click="form.tipo_pago = 'contado'"
                                        :class="form.tipo_pago === 'contado' ? 'bg-green-600 text-white' : 'bg-white dark:bg-gray-700 text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-600'"
                                        class="flex-1 py-2 text-sm font-medium transition-colors"
                                    >Contado</button>
                                    <button
                                        type="button"
                                        @click="form.tipo_pago = 'credito'"
                                        :class="form.tipo_pago === 'credito' ? 'bg-orange-500 text-white' : 'bg-white dark:bg-gray-700 text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-600'"
                                        class="flex-1 py-2 text-sm font-medium transition-colors border-l border-gray-300 dark:border-gray-600"
                                    >Crédito</button>
                                </div>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Fecha de la nota *</label>
                                <input type="date" v-model="form.fecha_factura" required class="w-full text-sm rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white" />
                                <p v-if="form.errors.fecha_factura" class="mt-1 text-xs text-red-500">{{ form.errors.fecha_factura }}</p>
                            </div>
                        </div>

                        <!-- Fila 3: número de factura + pedido (+ vencimiento si crédito) -->
                        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                                    N° factura/nota <span v-if="esCredito" class="text-red-500">*</span>
                                </label>
                                <input
                                    type="text"
                                    v-model="form.numero_factura"
                                    :required="esCredito"
                                    placeholder="ej. FAC-001"
                                    class="w-full text-sm rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white"
                                />
                                <p v-if="form.errors.numero_factura" class="mt-1 text-xs text-red-500">{{ form.errors.numero_factura }}</p>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">N° pedido proveedor</label>
                                <input type="text" v-model="form.numero_pedido" placeholder="Opcional" class="w-full text-sm rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white" />
                            </div>
                            <div v-if="esCredito">
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Fecha vencimiento *</label>
                                <input type="date" v-model="form.fecha_vencimiento" :required="esCredito" class="w-full text-sm rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white" />
                                <p v-if="form.errors.fecha_vencimiento" class="mt-1 text-xs text-red-500">{{ form.errors.fecha_vencimiento }}</p>
                            </div>
                        </div>

                        <!-- Artículos -->
                        <div>
                            <div class="flex items-center justify-between mb-2">
                                <label class="text-sm font-medium text-gray-700 dark:text-gray-300">Artículos recibidos *</label>
                                <div class="flex gap-2">
                                    <button type="button" @click="agregarItem('catalogo')" class="text-xs text-blue-600 dark:text-blue-400 hover:underline">+ Del catálogo</button>
                                    <button v-if="comprasLibresHabilitadas" type="button" @click="agregarItem('libre')" class="text-xs text-gray-500 dark:text-gray-400 hover:underline">+ Libre</button>
                                </div>
                            </div>

                            <div class="space-y-2">
                                <div
                                    v-for="(item, idx) in form.items"
                                    :key="idx"
                                    class="grid gap-2 items-end p-3 bg-gray-50 dark:bg-gray-700/50 rounded-lg"
                                    :style="{ gridTemplateColumns: item.tipo_item === 'catalogo' ? '2fr 1fr 1fr 1fr auto' : '2fr 1fr 1fr 1fr auto' }"
                                >
                                    <!-- Producto o descripción -->
                                    <div>
                                        <label class="block text-xs text-gray-500 dark:text-gray-400 mb-0.5">
                                            {{ item.tipo_item === 'catalogo' ? 'Producto' : 'Descripción libre' }}
                                        </label>
                                        <select
                                            v-if="item.tipo_item === 'catalogo'"
                                            v-model="item.product_id"
                                            required
                                            class="w-full text-xs rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white"
                                            @change="onProductoSeleccionado(item)"
                                        >
                                            <option value="">Seleccionar producto</option>
                                            <option v-for="p in productos" :key="p.id" :value="p.id">
                                                {{ p.name }}{{ p.barcode ? ' (' + p.barcode + ')' : '' }}
                                            </option>
                                        </select>
                                        <input
                                            v-else
                                            v-model="item.descripcion"
                                            type="text"
                                            required
                                            placeholder="Nombre del artículo"
                                            class="w-full text-xs rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white"
                                        />
                                    </div>

                                    <!-- Cantidad -->
                                    <div>
                                        <label class="block text-xs text-gray-500 dark:text-gray-400 mb-0.5">Cant.</label>
                                        <input
                                            type="number" v-model.number="item.quantity" min="1" step="1" required
                                            class="w-full text-xs rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white"
                                            @input="recalcular(item)"
                                        />
                                    </div>

                                    <!-- Costo unitario -->
                                    <div>
                                        <label class="block text-xs text-gray-500 dark:text-gray-400 mb-0.5">Costo unit.</label>
                                        <input
                                            type="number" v-model.number="item.cost" min="0" step="0.01" required
                                            class="w-full text-xs rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white"
                                            @input="recalcular(item)"
                                        />
                                    </div>

                                    <!-- Subtotal -->
                                    <div>
                                        <label class="block text-xs text-gray-500 dark:text-gray-400 mb-0.5">Subtotal</label>
                                        <input
                                            type="text" readonly
                                            :value="fmt(item.quantity * item.cost)"
                                            class="w-full text-xs rounded-md border-gray-200 dark:border-gray-600 bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-gray-400 cursor-default"
                                        />
                                    </div>

                                    <!-- Quitar -->
                                    <button
                                        type="button"
                                        @click="quitarItem(idx)"
                                        :disabled="form.items.length <= 1"
                                        class="mb-0.5 p-1.5 text-gray-400 hover:text-red-500 disabled:opacity-30 disabled:cursor-not-allowed transition-colors"
                                    >
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                                        </svg>
                                    </button>
                                </div>
                            </div>

                            <p v-if="form.errors.items" class="mt-1 text-xs text-red-500">{{ form.errors.items }}</p>
                        </div>

                        <!-- Total calculado -->
                        <div class="flex justify-end">
                            <div class="bg-gray-50 dark:bg-gray-700/50 rounded-lg px-4 py-2 flex items-center gap-4">
                                <span class="text-sm text-gray-600 dark:text-gray-400">Total</span>
                                <span class="text-xl font-bold text-gray-900 dark:text-white">{{ fmt(totalForm) }}</span>
                                <span v-if="esCredito" class="text-xs text-orange-600 dark:text-orange-400 font-medium">Se creará cuenta por pagar</span>
                                <span v-else class="text-xs text-green-600 dark:text-green-400 font-medium">Se registrará como gasto</span>
                            </div>
                        </div>

                        <!-- Notas -->
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Notas (opcional)</label>
                            <textarea v-model="form.notas" rows="2" placeholder="Observaciones del pedido..." class="w-full text-sm rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white resize-none" />
                        </div>

                        <!-- Foto adjunta -->
                        <div v-if="archivoPreview" class="flex items-center gap-3 p-2 bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-700 rounded-lg text-sm">
                            <img :src="archivoPreview" alt="Nota adjunta" class="w-10 h-10 object-cover rounded-md border border-blue-200 dark:border-blue-700" />
                            <span class="text-blue-700 dark:text-blue-300 flex-1">Se guardará la foto de la nota junto con este pedido.</span>
                            <button type="button" @click="iaReset()" class="text-blue-600 dark:text-blue-400 hover:underline text-xs">Quitar</button>
                        </div>

                        <!-- Botones -->
                        <div class="flex justify-end gap-3 pt-2">
                            <button type="button" @click="cerrarFormulario" class="px-4 py-2 text-sm text-gray-700 dark:text-gray-300 bg-gray-100 dark:bg-gray-700 hover:bg-gray-200 dark:hover:bg-gray-600 rounded-lg transition-colors">
                                Cancelar
                            </button>
                            <button
                                type="submit"
                                :disabled="form.processing"
                                class="px-6 py-2 text-sm font-medium bg-blue-600 hover:bg-blue-700 disabled:opacity-50 text-white rounded-lg transition-colors flex items-center gap-2"
                            >
                                <svg v-if="form.processing" class="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24">
                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
                                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z" />
                                </svg>
                                {{ form.processing ? 'Guardando...' : 'Registrar pedido' }}
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </Teleport>
    </AuthenticatedLayout>
</template>
