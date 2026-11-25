import '../css/app.css';
import { createApp, h, type DefineComponent } from 'vue';
import { createInertiaApp } from '@inertiajs/vue3';
import { createPinia } from 'pinia';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import WorkspaceShellLayout from './layouts/WorkspaceShellLayout.vue';

type InertiaPageComponent = DefineComponent & {
    layout?: unknown;
};

type InertiaPageModule = {
    default: InertiaPageComponent;
};

const appName = import.meta.env.VITE_APP_NAME || 'Workspace';

createInertiaApp({
    title: (title) => (title ? `${title} - ${appName}` : appName),
    resolve: async (name) => {
        const page = await resolvePageComponent(
            `./modules/${name}.vue`,
            import.meta.glob<InertiaPageModule>('./modules/**/*.vue'),
        );

        if (
            page.default.layout === undefined &&
            name !== 'core/pages/LoginPage' &&
            (name.startsWith('core/pages/') || name.includes('/pages/'))
        ) {
            page.default.layout = WorkspaceShellLayout;
        }

        return page.default;
    },
    setup({ el, App, props, plugin }) {
        const pinia = createPinia();

        createApp({ render: () => h(App, props) })
            .use(plugin)
            .use(pinia)
            .mount(el);
    },
    progress: {
        color: '#1D4ED8',
    },
});
