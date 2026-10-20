import { createApp } from 'vue';
import { createPinia } from 'pinia';
import App from './App.vue';
import { createAppRouter } from './router';
import './register-service-worker';

const pinia = createPinia();
const router = createAppRouter(pinia);

createApp(App).use(pinia).use(router).mount('#app');
