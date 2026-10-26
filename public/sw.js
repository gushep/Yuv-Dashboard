/*
 * Service worker mínimo para o app instalável (APK e "Adicionar à tela inicial").
 * Não guarda nada em cache: toda requisição vai ao servidor, então o app sempre mostra a versão publicada.
 */
self.addEventListener('install', () => self.skipWaiting());
self.addEventListener('activate', event => event.waitUntil(self.clients.claim()));
self.addEventListener('fetch', () => {});
