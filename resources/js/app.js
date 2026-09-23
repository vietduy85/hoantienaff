import './bootstrap';

import Alpine from 'alpinejs';
import TomSelect from 'tom-select';

window.Alpine = Alpine;
window.TomSelect = TomSelect;

Alpine.start();

if ('serviceWorker' in navigator) {
    window.addEventListener('load', () => {
        // PWA tat (A/B test): khong register SW moi, chi unregister SW cu tren origin nay.
        if (window.__PWA_ENABLED === false) {
            navigator.serviceWorker.getRegistrations()
                .then((registrations) => {
                    registrations.forEach((registration) => {
                        const scope = registration.scope || '';
                        if (scope.indexOf(window.location.origin) === 0) {
                            registration.unregister().catch(() => {});
                        }
                    });
                })
                .catch(() => {});
            return;
        }

        // PWA bat: register nhu binh thuong.
        navigator.serviceWorker.register('/sw.js').catch(() => {});
    });
}
