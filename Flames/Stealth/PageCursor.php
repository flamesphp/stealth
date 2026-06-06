<?php
declare(strict_types=1);


namespace Flames\Stealth;

/**
 * DOM cursor fallback for local dev without X11 overlay (detectable — avoid in production).
 *
 * @internal
 */
final class PageCursor
{
    public static function bootstrap(): string
    {
        return <<<'JS'
(function () {
    if (window.__stealthCursor) {
        return;
    }

    const style = document.createElement('style');
    style.textContent = [
        '#__stealth_cursor__ {',
        '  position: fixed;',
        '  z-index: 2147483647;',
        '  pointer-events: none;',
        '  width: 28px;',
        '  height: 28px;',
        '  margin: 0;',
        '  background: url("data:image/svg+xml,%3Csvg xmlns=\'http://www.w3.org/2000/svg\' width=\'28\' height=\'28\' viewBox=\'0 0 24 24\'%3E%3Cpath fill=\'%23fff\' stroke=\'%23000\' stroke-width=\'1.2\' d=\'M4 4l14 8-6 1-3 7z\'/%3E%3C/svg%3E") center/contain no-repeat;',
        '  filter: drop-shadow(0 1px 2px rgba(0,0,0,.6));',
        '  transform: translate(0, 0);',
        '  transition: transform 80ms ease-out;',
        '}',
        '#__stealth_cursor__.__stealth_cursor_press__ {',
        '  transform: scale(0.82);',
        '}',
        '.__stealth_click_ripple__ {',
        '  position: fixed;',
        '  width: 36px;',
        '  height: 36px;',
        '  border: 2px solid rgba(66, 133, 244, 0.95);',
        '  background: rgba(66, 133, 244, 0.15);',
        '  border-radius: 50%;',
        '  pointer-events: none;',
        '  z-index: 2147483646;',
        '  animation: __stealth_ripple__ 420ms ease-out forwards;',
        '  transform: translate(-20%, -10%) scale(0.35);',
        '}',
        '@keyframes __stealth_ripple__ {',
        '  to {',
        '    transform: translate(-20%, -10%) scale(2.4);',
        '    opacity: 0;',
        '  }',
        '}',
    ].join('');
    document.documentElement.appendChild(style);

    const el = document.createElement('div');
    el.id = '__stealth_cursor__';
    el.style.display = 'none';
    document.documentElement.appendChild(el);

    window.__stealthCursor = {
        move(x, y) {
            el.style.left = Math.round(x) + 'px';
            el.style.top = Math.round(y) + 'px';
            el.style.display = 'block';
        },
        hide() {
            el.style.display = 'none';
        },
        click(x, y) {
            this.move(x, y);
            el.classList.add('__stealth_cursor_press__');
            setTimeout(() => el.classList.remove('__stealth_cursor_press__'), 100);

            const ripple = document.createElement('div');
            ripple.className = '__stealth_click_ripple__';
            ripple.style.left = Math.round(x) + 'px';
            ripple.style.top = Math.round(y) + 'px';
            document.documentElement.appendChild(ripple);
            ripple.addEventListener('animationend', () => ripple.remove(), { once: true });
        },
    };
})();
JS;
    }

    public static function move(float $x, float $y): string
    {
        $x = round($x, 1);
        $y = round($y, 1);

        return "window.__stealthCursor?.move({$x}, {$y});";
    }

    public static function click(float $x, float $y): string
    {
        $x = round($x, 1);
        $y = round($y, 1);

        return "window.__stealthCursor?.click({$x}, {$y});";
    }
}
