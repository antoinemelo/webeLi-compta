<?php
declare(strict_types=1);

namespace Compta\Modules\Tresorerie\Parsing;

final class SecureXmlNode
{
    /** @var list<self> */
    public array $children = [];
    public string $text = '';

    /** @param array<string,string> $attributes */
    public function __construct(
        public readonly string $name,
        public readonly array $attributes = [],
    ) {
    }

    public function localName(): string
    {
        $position = strrpos($this->name, ':');
        return $position === false ? $this->name : substr($this->name, $position + 1);
    }

    public function child(string $name): ?self
    {
        foreach ($this->children as $child) {
            if ($child->localName() === $name) {
                return $child;
            }
        }
        return null;
    }

    /** @return list<self> */
    public function childrenNamed(string $name): array
    {
        return array_values(array_filter(
            $this->children,
            static fn (self $child): bool => $child->localName() === $name
        ));
    }

    /** @return list<self> */
    public function descendants(string $name): array
    {
        $found = [];
        foreach ($this->children as $child) {
            if ($child->localName() === $name) {
                $found[] = $child;
            }
            array_push($found, ...$child->descendants($name));
        }
        return $found;
    }

    public function value(): string
    {
        return trim($this->text);
    }

    public function path(string ...$names): ?self
    {
        $node = $this;
        foreach ($names as $name) {
            $node = $node->child($name);
            if ($node === null) {
                return null;
            }
        }
        return $node;
    }

    public function pathValue(string ...$names): string
    {
        return $this->path(...$names)?->value() ?? '';
    }

    public function attribute(string $name): string
    {
        return $this->attributes[$name] ?? '';
    }
}
