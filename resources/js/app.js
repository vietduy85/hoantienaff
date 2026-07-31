import './bootstrap';

import Alpine from 'alpinejs';
import TomSelect from 'tom-select';

window.Alpine = Alpine;
window.TomSelect = TomSelect;

Alpine.start();

if ('serviceWorker' in navigator) {
    window.addEventListener('load', () => {
        navigator.serviceWorker.register('/sw.js').catch(() => {});
    });
}
