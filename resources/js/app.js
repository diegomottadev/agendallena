import { createApp } from 'vue';
import IntegracionesPanel from './components/IntegracionesPanel.vue';

/*
 * T-012 · Shell mínimo del panel.
 *
 * Se monta sobre un `#panel` que la vista Blade rellena con los datos ya
 * serializados. No hay router ni store todavía a propósito: el alcance del
 * ticket es "shell mínimo con la pantalla de integraciones", y meter una SPA
 * completa acá adelantaría decisiones que corresponden a T-046 y T-047.
 */
const raiz = document.getElementById('panel');

if (raiz) {
    createApp(IntegracionesPanel, {
        integraciones: JSON.parse(raiz.dataset.integraciones || '[]'),
        tenant: raiz.dataset.tenant || '',
        puedeConfigurar: raiz.dataset.puedeConfigurar === '1',
    }).mount(raiz);
}
