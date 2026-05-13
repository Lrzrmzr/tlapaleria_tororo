<script setup>
import { ref, nextTick } from 'vue';
import axios from 'axios';

const abierto    = ref(false);
const pregunta   = ref('');
const cargando   = ref(false);
const mensajes   = ref([
    {
        tipo: 'ia',
        texto: '¡Hola! Soy tu asistente. Puedo ayudarte con precios, existencias, ventas, deudas o cualquier dato del sistema. ¿Qué necesitas saber?',
        datos: [],
        columnas: [],
    },
]);
const inputRef      = ref(null);
const mensajesRef   = ref(null);

// Tabla expandida por mensaje
const tablaExpandida = ref({});

function toggleTabla(idx) {
    tablaExpandida.value[idx] = !tablaExpandida.value[idx];
}

async function scrollAbajo() {
    await nextTick();
    if (mensajesRef.value) {
        mensajesRef.value.scrollTop = mensajesRef.value.scrollHeight;
    }
}

function abrir() {
    abierto.value = true;
    nextTick(() => inputRef.value?.focus());
    scrollAbajo();
}

function cerrar() {
    abierto.value = false;
}

async function enviar() {
    const texto = pregunta.value.trim();
    if (!texto || cargando.value) return;

    // Agregar mensaje del usuario
    mensajes.value.push({ tipo: 'usuario', texto });
    pregunta.value = '';
    cargando.value  = true;
    scrollAbajo();

    try {
        const { data } = await axios.post('/asistente', { pregunta: texto });

        mensajes.value.push({
            tipo:     'ia',
            texto:    data.respuesta || data.error || 'Sin respuesta.',
            datos:    data.datos    ?? [],
            columnas: data.columnas ?? [],
            error:    !!data.error,
        });
    } catch (e) {
        const msg = e.response?.data?.error
            || e.response?.data?.respuesta
            || 'No se pudo contactar el asistente. Verifica tu conexión e intenta de nuevo.';
        mensajes.value.push({
            tipo:  'ia',
            texto: msg,
            error: true,
            datos: [], columnas: [],
        });
    } finally {
        cargando.value = false;
        scrollAbajo();
        nextTick(() => inputRef.value?.focus());
    }
}

function onKeydown(e) {
    if (e.key === 'Enter' && !e.shiftKey) {
        e.preventDefault();
        enviar();
    }
}

// Formatear valor de celda
function fmtCell(val) {
    if (val === null || val === undefined) return '—';
    if (typeof val === 'number') {
        // Si parece precio (decimal con 2 cifras), formatearlo
        if (!Number.isInteger(val)) {
            return new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' }).format(val);
        }
    }
    return String(val);
}

// Sugerencias rápidas
const sugerencias = [
    '¿Cuánto cuesta el cemento?',
    '¿Qué productos tienen stock bajo?',
    '¿Cuánto vendimos hoy?',
    '¿Qué clientes tienen deudas pendientes?',
    '¿Cuáles son los 5 productos más vendidos?',
];

function usarSugerencia(s) {
    pregunta.value = s;
    enviar();
}
</script>

<template>
    <!-- Botón flotante -->
    <div class="fixed bottom-6 right-6 z-50 flex flex-col items-end gap-3">

        <!-- Panel de chat -->
        <Transition
            enter-active-class="transition duration-200 ease-out"
            enter-from-class="opacity-0 translate-y-4 scale-95"
            enter-to-class="opacity-100 translate-y-0 scale-100"
            leave-active-class="transition duration-150 ease-in"
            leave-from-class="opacity-100 translate-y-0 scale-100"
            leave-to-class="opacity-0 translate-y-4 scale-95"
        >
            <div
                v-if="abierto"
                class="w-96 max-w-[calc(100vw-2rem)] bg-white dark:bg-gray-900 rounded-2xl shadow-2xl border border-gray-200 dark:border-gray-700 flex flex-col overflow-hidden"
                style="height: 520px;"
            >
                <!-- Header -->
                <div class="flex items-center gap-3 px-4 py-3 bg-gradient-to-r from-blue-600 to-indigo-600 text-white flex-shrink-0">
                    <div class="w-8 h-8 rounded-full bg-white/20 flex items-center justify-center">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                  d="M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17H3a2 2 0 01-2-2V5a2 2 0 012-2h14a2 2 0 012 2v10a2 2 0 01-2 2h-2"/>
                        </svg>
                    </div>
                    <div class="flex-1 min-w-0">
                        <div class="font-semibold text-sm">Asistente IA</div>
                        <div class="text-xs text-blue-200">Pregunta sobre el negocio</div>
                    </div>
                    <button @click="cerrar"
                            class="w-7 h-7 rounded-lg hover:bg-white/20 flex items-center justify-center transition-colors">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                        </svg>
                    </button>
                </div>

                <!-- Mensajes -->
                <div ref="mensajesRef"
                     class="flex-1 overflow-y-auto px-4 py-3 space-y-3 scroll-smooth">

                    <div v-for="(msg, idx) in mensajes" :key="idx"
                         :class="['flex', msg.tipo === 'usuario' ? 'justify-end' : 'justify-start']">

                        <!-- Burbuja IA -->
                        <div v-if="msg.tipo === 'ia'" class="flex gap-2 max-w-[90%]">
                            <div class="w-7 h-7 rounded-full bg-indigo-100 dark:bg-indigo-900 flex items-center justify-center flex-shrink-0 mt-0.5">
                                <svg class="w-4 h-4 text-indigo-600 dark:text-indigo-400" fill="currentColor" viewBox="0 0 20 20">
                                    <path d="M10 2a1 1 0 00-1 1v1a1 1 0 002 0V3a1 1 0 00-1-1zM4 4h3a3 3 0 006 0h3a2 2 0 012 2v9a2 2 0 01-2 2H4a2 2 0 01-2-2V6a2 2 0 012-2zm2.5 7a1.5 1.5 0 100-3 1.5 1.5 0 000 3zm5 0a1.5 1.5 0 100-3 1.5 1.5 0 000 3zM9 13h2a1 1 0 110 2H9a1 1 0 110-2z"/>
                                </svg>
                            </div>
                            <div class="space-y-2">
                                <!-- Texto de respuesta -->
                                <div :class="[
                                    'rounded-2xl rounded-tl-sm px-3 py-2.5 text-sm leading-relaxed whitespace-pre-wrap',
                                    msg.error
                                        ? 'bg-red-50 dark:bg-red-900/20 text-red-700 dark:text-red-300 border border-red-200 dark:border-red-700'
                                        : 'bg-gray-100 dark:bg-gray-800 text-gray-800 dark:text-gray-200'
                                ]">
                                    {{ msg.texto }}
                                </div>

                                <!-- Tabla de datos (si hay) -->
                                <div v-if="msg.datos && msg.datos.length > 0" class="text-xs">
                                    <button
                                        @click="toggleTabla(idx)"
                                        class="flex items-center gap-1 text-indigo-600 dark:text-indigo-400 hover:underline mb-1 font-medium"
                                    >
                                        <svg class="w-3.5 h-3.5 transition-transform" :class="tablaExpandida[idx] ? 'rotate-90' : ''"
                                             fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
                                        </svg>
                                        {{ tablaExpandida[idx] ? 'Ocultar' : 'Ver' }} datos ({{ msg.datos.length }} fila{{ msg.datos.length !== 1 ? 's' : '' }})
                                    </button>

                                    <div v-if="tablaExpandida[idx]"
                                         class="overflow-x-auto rounded-lg border border-gray-200 dark:border-gray-700">
                                        <table class="min-w-full text-xs">
                                            <thead>
                                                <tr class="bg-gray-50 dark:bg-gray-800">
                                                    <th v-for="col in msg.columnas" :key="col"
                                                        class="px-2 py-1.5 text-left font-semibold text-gray-600 dark:text-gray-300 whitespace-nowrap border-b border-gray-200 dark:border-gray-700">
                                                        {{ col }}
                                                    </th>
                                                </tr>
                                            </thead>
                                            <tbody class="divide-y divide-gray-100 dark:divide-gray-700/50">
                                                <tr v-for="(row, ri) in msg.datos" :key="ri"
                                                    class="hover:bg-gray-50 dark:hover:bg-gray-800/50">
                                                    <td v-for="col in msg.columnas" :key="col"
                                                        class="px-2 py-1.5 text-gray-700 dark:text-gray-300 whitespace-nowrap max-w-[160px] truncate">
                                                        {{ fmtCell(row[col]) }}
                                                    </td>
                                                </tr>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Burbuja usuario -->
                        <div v-else
                             class="rounded-2xl rounded-tr-sm px-3 py-2.5 text-sm bg-blue-600 text-white max-w-[80%] leading-relaxed">
                            {{ msg.texto }}
                        </div>
                    </div>

                    <!-- Indicador de carga -->
                    <div v-if="cargando" class="flex justify-start">
                        <div class="flex gap-2">
                            <div class="w-7 h-7 rounded-full bg-indigo-100 dark:bg-indigo-900 flex items-center justify-center flex-shrink-0">
                                <svg class="w-4 h-4 text-indigo-600 dark:text-indigo-400 animate-spin" fill="none" viewBox="0 0 24 24">
                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/>
                                </svg>
                            </div>
                            <div class="bg-gray-100 dark:bg-gray-800 rounded-2xl rounded-tl-sm px-3 py-2.5 flex items-center gap-1">
                                <span class="w-1.5 h-1.5 rounded-full bg-gray-400 animate-bounce" style="animation-delay:0ms"></span>
                                <span class="w-1.5 h-1.5 rounded-full bg-gray-400 animate-bounce" style="animation-delay:150ms"></span>
                                <span class="w-1.5 h-1.5 rounded-full bg-gray-400 animate-bounce" style="animation-delay:300ms"></span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Sugerencias (solo al inicio) -->
                <div v-if="mensajes.length === 1 && !cargando"
                     class="px-3 pb-2 flex flex-wrap gap-1.5 flex-shrink-0">
                    <button
                        v-for="s in sugerencias" :key="s"
                        @click="usarSugerencia(s)"
                        class="text-xs px-2.5 py-1 rounded-full border border-indigo-200 dark:border-indigo-700 text-indigo-600 dark:text-indigo-400 hover:bg-indigo-50 dark:hover:bg-indigo-900/30 transition-colors whitespace-nowrap"
                    >
                        {{ s }}
                    </button>
                </div>

                <!-- Input -->
                <div class="border-t border-gray-200 dark:border-gray-700 px-3 py-2.5 flex gap-2 items-end flex-shrink-0">
                    <textarea
                        ref="inputRef"
                        v-model="pregunta"
                        @keydown="onKeydown"
                        :disabled="cargando"
                        rows="1"
                        placeholder="Escribe tu pregunta..."
                        class="flex-1 resize-none rounded-xl border border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white px-3 py-2 text-sm focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 disabled:opacity-50 max-h-28 overflow-y-auto"
                        style="field-sizing: content; min-height: 36px;"
                    ></textarea>
                    <button
                        @click="enviar"
                        :disabled="!pregunta.trim() || cargando"
                        class="w-9 h-9 rounded-xl bg-indigo-600 hover:bg-indigo-700 disabled:opacity-40 disabled:cursor-not-allowed text-white flex items-center justify-center transition-colors flex-shrink-0"
                    >
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8"/>
                        </svg>
                    </button>
                </div>
            </div>
        </Transition>

        <!-- Botón flotante principal -->
        <button
            @click="abierto ? cerrar() : abrir()"
            :class="[
                'w-14 h-14 rounded-2xl shadow-lg flex items-center justify-center transition-all duration-200',
                abierto
                    ? 'bg-gray-700 dark:bg-gray-600 hover:bg-gray-800 dark:hover:bg-gray-500 rotate-90'
                    : 'bg-gradient-to-br from-blue-600 to-indigo-600 hover:from-blue-700 hover:to-indigo-700 hover:scale-105'
            ]"
        >
            <svg v-if="!abierto" class="w-7 h-7 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8"
                      d="M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17H3a2 2 0 01-2-2V5a2 2 0 012-2h14a2 2 0 012 2v10a2 2 0 01-2 2h-2"/>
            </svg>
            <svg v-else class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
            </svg>
        </button>
    </div>
</template>
