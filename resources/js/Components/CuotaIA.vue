<script setup>
import { computed } from 'vue';

/**
 * Muestra la cuota gratuita restante de Groq (GroqClient::estado).
 * `porConsulta` = peticiones a Groq que cuesta una consulta (el asistente usa 2).
 */
const props = defineProps({
    cuota:       { type: Object, default: null },
    porConsulta: { type: Number, default: 1 },
    unidad:      { type: String, default: 'consultas' },
});

const restantes = computed(() => {
    const r = props.cuota?.restantes_dia;
    return r === null || r === undefined ? null : Math.floor(r / props.porConsulta);
});

const limite = computed(() => {
    const l = props.cuota?.limite_dia;
    return l === null || l === undefined ? null : Math.floor(l / props.porConsulta);
});

const bloqueado = computed(() => !!props.cuota?.bloqueado_hasta || restantes.value === 0);

const porcentaje = computed(() =>
    restantes.value === null || !limite.value ? null : Math.round((restantes.value / limite.value) * 100)
);

function relativo(iso) {
    if (!iso) return null;
    const seg = Math.max(0, Math.round((new Date(iso) - Date.now()) / 1000));
    if (seg < 60) return `en ${seg} s`;
    if (seg < 3600) return `en ${Math.round(seg / 60)} min`;
    return `en ${Math.round(seg / 3600)} h`;
}

const reinicio = computed(() => relativo(props.cuota?.bloqueado_hasta || props.cuota?.reinicia));

const texto = computed(() => {
    if (!props.cuota) return 'Cuota de IA: cargando…';
    if (bloqueado.value) return `Límite gratuito agotado${reinicio.value ? ' · se reinicia ' + reinicio.value : ''}`;
    if (restantes.value === null) return 'Cuota de IA: se conocerá tras la primera consulta del día';
    return `Quedan ${restantes.value} de ${limite.value} ${props.unidad} hoy`;
});
</script>

<template>
    <div class="flex items-center gap-2 text-xs" :title="cuota?.modelo ? `Modelo: ${cuota.modelo}` : null" aria-live="polite">
        <span
            class="inline-block w-2 h-2 rounded-full flex-shrink-0"
            :class="bloqueado ? 'bg-red-500' : (porcentaje !== null && porcentaje < 20 ? 'bg-amber-500' : 'bg-emerald-500')"
        ></span>
        <span>{{ texto }}</span>
    </div>
</template>
