<?php
declare(strict_types=1);


namespace Flames\Stealth\Element;

use Flames\Collection\Arr;
use Flames\Stealth\Element;

/**
 * @property-read Arr<int, string> $all
 */
final class ClassList
{
    public function __construct(
        private readonly Element $element,
    ) {}

    public function add(string $class): void
    {
        $class = strtolower(trim($class));

        if ($class === '' || $this->has($class)) {
            return;
        }

        $classes = $this->classes();
        $classes[] = $class;
        $this->element->attribute()->set('class', implode(' ', $classes));
    }

    public function has(string $class): bool
    {
        $class = strtolower(trim($class));

        return in_array($class, $this->classes(), true);
    }

    public function remove(string $class): void
    {
        $class = strtolower(trim($class));

        if ($class === '' || !$this->has($class)) {
            return;
        }

        $classes = array_values(array_filter(
            $this->classes(),
            static fn (string $item): bool => $item !== $class,
        ));

        if ($classes === []) {
            $this->element->attribute()->remove('class');
            return;
        }

        $this->element->attribute()->set('class', implode(' ', $classes));
    }

    public function toggle(string $class): void
    {
        if ($this->has($class)) {
            $this->remove($class);
            return;
        }

        $this->add($class);
    }

    public function all(): Arr
    {
        return Arr($this->classes());
    }

    public function __get(string $name): mixed
    {
        return match ($name) {
            'all' => $this->all(),
            default => throw new \InvalidArgumentException("Unknown property: {$name}"),
        };
    }

    /** @return list<string> */
    private function classes(): array
    {
        $raw = (string) ($this->element->attribute()->get('class') ?? '');

        if ($raw === '') {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn (string $class): string => strtolower(trim($class)),
            preg_split('/\s+/', $raw) ?: [],
        )));
    }
}
