<?php
declare(strict_types=1);


namespace Flames\Stealth;

/**
 * A DOM element resolved in the browser via CSS selector.
 */
final class Element
{
    public function __construct(
        private readonly Stealth $browser,
        private readonly int $nodeId,
        private readonly string $selector,
    ) {}

    public function selector(): string
    {
        return $this->selector;
    }

    public function nodeId(): int
    {
        return $this->nodeId;
    }

    public function hover(): void
    {
        $this->browser->hoverNode($this->nodeId, $this->selector);
    }

    public function click(): void
    {
        $this->browser->clickNode($this->nodeId, $this->selector);
    }

    public function type(string $text): void
    {
        $this->click();
        asleep(random_int(80, 200) / 1000.0);
        $this->browser->typeHuman($text);
    }
}
