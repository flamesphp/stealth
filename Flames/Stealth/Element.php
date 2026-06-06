<?php
declare(strict_types=1);


namespace Flames\Stealth;

use Flames\Collection\Arr;
use Flames\Stealth\Element\Attribute;
use Flames\Stealth\Element\ClassList;
use Flames\Stealth\Element\ShadowRoot;
use Flames\Stealth\Exception\StealthException;

/**
 * A DOM element resolved in the browser.
 *
 * @property string|null $html
 * @property mixed $value
 * @property string|null $tag
 * @property self|null $parent
 * @property ShadowRoot|null $shadow
 * @property ClassList $class
 * @property Attribute $attribute
 */
final class Element
{
    private bool $destroyed = false;

    public function __construct(
        private readonly Stealth $browser,
        private int $nodeId,
        private readonly string $selector = '',
    ) {}

    public function selector(): string
    {
        return $this->selector;
    }

    public function nodeId(): int
    {
        $this->assertAlive();

        return $this->nodeId;
    }

    /** @internal */
    public function stealth(): Stealth
    {
        return $this->browser;
    }

    public function hover(): void
    {
        $this->assertAlive();
        $this->browser->hoverNode($this->nodeId, $this->selector);
    }

    public function click(): void
    {
        $this->assertAlive();
        $this->browser->clickNode($this->nodeId, $this->selector);
    }

    public function type(string $text): void
    {
        $this->click();
        asleep(random_int(80, 200) / 1000.0);
        $this->browser->typeHuman($text);
    }

    public function query(string $selector): self|false
    {
        $this->assertAlive();

        $nodeId = $this->browser->domQuery($this->nodeId, $selector);

        if ($nodeId === null) {
            return false;
        }

        return new self($this->browser, $nodeId, $selector);
    }

    public function queryAll(string $selector): Arr
    {
        $this->assertAlive();

        $elements = [];

        foreach ($this->browser->domQueryAll($this->nodeId, $selector) as $nodeId) {
            $elements[] = new self($this->browser, $nodeId, $selector);
        }

        return Arr($elements);
    }

    public function attribute(): Attribute
    {
        return new Attribute($this);
    }

    public function destroy(): void
    {
        if ($this->destroyed) {
            return;
        }

        $this->browser->domCall($this->nodeId, 'function () { this.remove(); }');
        $this->destroyed = true;
        $this->nodeId = 0;
    }

    public function __get(string $name): mixed
    {
        $this->assertAlive();

        return match (strtolower($name)) {
            'html'      => $this->getHtml(),
            'value'     => $this->getValue(),
            'tag'       => $this->getTag(),
            'parent'    => $this->getParent(),
            'shadow'    => $this->getShadow(),
            'class'     => new ClassList($this),
            'attribute' => new Attribute($this),
            default     => throw new StealthException("Unknown property: {$name}"),
        };
    }

    public function __set(string $name, mixed $value): void
    {
        $this->assertAlive();

        match (strtolower($name)) {
            'html'  => $this->setHtml((string) $value),
            'value' => $this->setValue($value),
            default => throw new StealthException("Unknown property: {$name}"),
        };
    }

    private function getHtml(): ?string
    {
        return $this->browser->domCall(
            $this->nodeId,
            'function () { return this.innerHTML; }',
        );
    }

    private function setHtml(string $html): void
    {
        $this->browser->domCall(
            $this->nodeId,
            'function (html) { this.innerHTML = html; }',
            [$html],
        );
    }

    private function getValue(): mixed
    {
        return $this->browser->domCall(
            $this->nodeId,
            <<<'JS'
function () {
    const tag = this.tagName.toLowerCase();

    if (tag === 'textarea' || tag === 'select') {
        return this.value;
    }

    if (tag === 'input') {
        const type = (this.getAttribute('type') || '').toLowerCase();

        if (type === 'checkbox') {
            return this.checked;
        }

        return this.value;
    }

    return null;
}
JS,
        );
    }

    private function setValue(mixed $value): void
    {
        $this->browser->domCall(
            $this->nodeId,
            <<<'JS'
function (value) {
    const tag = this.tagName.toLowerCase();

    if (tag === 'textarea' || tag === 'select') {
        this.value = String(value);
        return;
    }

    if (tag === 'input') {
        const type = (this.getAttribute('type') || '').toLowerCase();

        if (type === 'checkbox') {
            this.checked = value === true;
            return;
        }

        this.value = String(value);
    }
}
JS,
            [$value],
        );
    }

    private function getTag(): ?string
    {
        $tag = $this->browser->domTag($this->nodeId);

        return $tag !== '' ? $tag : null;
    }

    private function getParent(): ?self
    {
        $parentId = $this->browser->domParentId($this->nodeId);

        if ($parentId === null) {
            return null;
        }

        return new self($this->browser, $parentId);
    }

    private function getShadow(): ?ShadowRoot
    {
        $objectId = $this->browser->domCall(
            $this->nodeId,
            'function () { return this.shadowRoot; }',
            returnObject: true,
        );

        if (!is_string($objectId) || $objectId === '') {
            return null;
        }

        return new ShadowRoot($this, $objectId);
    }

    private function assertAlive(): void
    {
        if ($this->destroyed || $this->nodeId <= 0) {
            throw new StealthException('Element has been destroyed.');
        }
    }
}
