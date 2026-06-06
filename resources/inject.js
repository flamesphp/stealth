(() => {
    const VENDOR = 'Intel Inc.';
    const RENDERER = 'ANGLE (Intel, Mesa Intel(R) UHD Graphics 630 (CFL GT2), OpenGL 4.6)';

    const VENDOR_WEBGL = 0x9245;
    const RENDERER_WEBGL = 0x9246;

    const patchWebGl = (context) => {
        if (!context || context.__flamesStealth) {
            return context;
        }

        const originalGetParameter = context.getParameter.bind(context);
        const originalGetExtension = context.getExtension.bind(context);

        context.getExtension = function (name) {
            const ext = originalGetExtension(name);
            if (name === 'WEBGL_debug_renderer_info' && ext) {
                return ext;
            }
            return ext;
        };

        context.getParameter = function (parameter) {
            if (parameter === VENDOR_WEBGL) {
                return VENDOR;
            }
            if (parameter === RENDERER_WEBGL) {
                return RENDERER;
            }
            return originalGetParameter(parameter);
        };

        context.__flamesStealth = true;
        return context;
    };

    const patchGetContext = (prototype) => {
        if (!prototype || prototype.__flamesStealth) {
            return;
        }

        const originalGetContext = prototype.getContext.bind(prototype);

        prototype.getContext = function (type, attributes) {
            const context = originalGetContext(type, attributes);
            if (context && (type === 'webgl' || type === 'webgl2' || type === 'experimental-webgl')) {
                return patchWebGl(context);
            }
            return context;
        };

        prototype.__flamesStealth = true;
    };

    patchGetContext(HTMLCanvasElement.prototype);
    patchGetContext(OffscreenCanvas.prototype);

    Object.defineProperty(navigator, 'webdriver', { get: () => undefined });
    window.chrome = window.chrome || { runtime: {} };
})();
