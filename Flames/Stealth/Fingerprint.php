<?php
declare(strict_types=1);


namespace Flames\Stealth;

use Flames\Env\Env;

/**
 * Browser fingerprint patches injected before any page script runs.
 *
 * @internal
 */
final class Fingerprint
{
    private const string DEFAULT_WEBGL_VENDOR = 'Google Inc. (NVIDIA)';

    private const string DEFAULT_WEBGL_RENDERER = 'ANGLE (NVIDIA, NVIDIA GeForce RTX 3060 (0x00002487) Direct3D11 vs_5_0 ps_5_0, D3D11)';

    public static function webglVendor(): string
    {
        return (string) (Env::get('STEALTH_WEBGL_VENDOR') ?? self::DEFAULT_WEBGL_VENDOR);
    }

    public static function webglRenderer(): string
    {
        return (string) (Env::get('STEALTH_WEBGL_RENDERER') ?? self::DEFAULT_WEBGL_RENDERER);
    }

    public static function evaluateOnNewDocumentScript(): string
    {
        $vendor = json_encode(self::webglVendor(), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $renderer = json_encode(self::webglRenderer(), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        return <<<JS
(function () {
    if (window.__stealthFingerprint) {
        return;
    }

    window.__stealthFingerprint = true;

    const VENDOR = {$vendor};
    const RENDERER = {$renderer};
    const UNMASKED_VENDOR_WEBGL = 0x9245;
    const UNMASKED_RENDERER_WEBGL = 0x9246;
    const VENDOR_PARAM = 0x1F00;
    const RENDERER_PARAM = 0x1F01;

    const spoofParameter = (target, param) => {
        if (param === UNMASKED_VENDOR_WEBGL || param === VENDOR_PARAM) {
            return VENDOR;
        }

        if (param === UNMASKED_RENDERER_WEBGL || param === RENDERER_PARAM) {
            return RENDERER;
        }

        return null;
    };

    const patchContext = (context) => {
        if (!context || context.__stealthWebglPatched) {
            return;
        }

        const originalGetParameter = context.getParameter;

        context.getParameter = function (param) {
            const spoofed = spoofParameter(this, param);

            if (spoofed !== null) {
                return spoofed;
            }

            return originalGetParameter.call(this, param);
        };

        context.__stealthWebglPatched = true;
    };

    const patchPrototype = (proto) => {
        if (!proto || proto.__stealthWebglPatched) {
            return;
        }

        const originalGetParameter = proto.getParameter;

        proto.getParameter = function (param) {
            const spoofed = spoofParameter(this, param);

            if (spoofed !== null) {
                return spoofed;
            }

            return originalGetParameter.call(this, param);
        };

        proto.__stealthWebglPatched = true;
    };

    patchPrototype(WebGLRenderingContext?.prototype);
    patchPrototype(WebGL2RenderingContext?.prototype);

    const originalGetContext = HTMLCanvasElement.prototype.getContext;

    HTMLCanvasElement.prototype.getContext = function (type, attributes) {
        const context = originalGetContext.call(this, type, attributes);

        if (context && typeof type === 'string' && type.indexOf('webgl') !== -1) {
            patchContext(context);
        }

        return context;
    };

    if (typeof OffscreenCanvas !== 'undefined') {
        const originalOffscreenGetContext = OffscreenCanvas.prototype.getContext;

        OffscreenCanvas.prototype.getContext = function (type, attributes) {
            const context = originalOffscreenGetContext.call(this, type, attributes);

            if (context && typeof type === 'string' && type.indexOf('webgl') !== -1) {
                patchContext(context);
            }

            return context;
        };
    }
})();
JS;
    }
}
