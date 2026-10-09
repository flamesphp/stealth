<?php
declare(strict_types=1);


namespace Flames\Stealth\Element;

use Flames\Stealth\Element;

final readonly class Attribute
{
    public function __construct(
        private Element $element,
    ) {}

    public function get(string $attr): ?string
    {
        if ($this->isStyleProperty($attr)) {
            return $this->element->stealth()->domCall(
                $this->element->nodeId(),
                <<<'JS'
function (name) {
    return window.getComputedStyle(this).getPropertyValue(name);
}
JS,
                [$attr],
            );
        }

        return $this->element->stealth()->domCall(
            $this->element->nodeId(),
            <<<'JS'
function (name) {
    return this.getAttribute(name);
}
JS,
            [$attr],
        );
    }

    public function set(string $attr, mixed $value, bool $important = false): void
    {
        if ($this->isStyleProperty($attr)) {
            $this->element->stealth()->domCall(
                $this->element->nodeId(),
                <<<'JS'
function (name, value, important) {
    this.style.setProperty(name, String(value), important ? 'important' : '');
}
JS,
                [$attr, $value, $important],
            );

            return;
        }

        $this->element->stealth()->domCall(
            $this->element->nodeId(),
            <<<'JS'
function (name, value) {
    this.setAttribute(name, String(value));
}
JS,
            [$attr, $value],
        );
    }

    public function remove(string $attr): void
    {
        if ($this->isStyleProperty($attr)) {
            $this->element->stealth()->domCall(
                $this->element->nodeId(),
                <<<'JS'
function (name) {
    this.style.removeProperty(name);
}
JS,
                [$attr],
            );

            return;
        }

        $this->element->stealth()->domCall(
            $this->element->nodeId(),
            <<<'JS'
function (name) {
    this.removeAttribute(name);
}
JS,
            [$attr],
        );
    }

    private function isStyleProperty(string $attr): bool
    {
        return str_contains($attr, '-');
    }
}
