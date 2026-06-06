<?php
declare(strict_types=1);


namespace Flames\Stealth\Element;

use Flames\Stealth\Element;

final class ShadowRoot
{
    public function __construct(
        private readonly Element $host,
        private readonly string $objectId,
    ) {}

    public function query(string $selector): Element|false
    {
        $objectId = $this->host->stealth()->domCallObject(
            $this->objectId,
            <<<'JS'
function (selector) {
    return this.querySelector(selector);
}
JS,
            [$selector],
            returnObject: true,
        );

        if (!is_string($objectId) || $objectId === '') {
            return false;
        }

        $nodeId = $this->host->stealth()->domNodeId($objectId);

        if ($nodeId <= 0) {
            return false;
        }

        return new Element($this->host->stealth(), $nodeId, $selector);
    }
}
