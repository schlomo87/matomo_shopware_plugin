import MatomoResolutionPlugin from './matomo-cookie-handler';

try {
    const PluginManager = window.PluginManager;
    PluginManager.register('MatomoResolutionPlugin', MatomoResolutionPlugin, 'body');
} catch (e) {
    console.error('Matomo Plugin registration failed:', e);
}