<?php

namespace App\Source;

use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/** The source integrations, by identifier. */
final class SourceRegistry
{
    /** @var array<string, SourceTypeInterface> */
    private array $types = [];

    /** @param iterable<SourceTypeInterface> $types */
    public function __construct(#[AutowireIterator('cast.source_type')] iterable $types)
    {
        foreach ($types as $type) {
            $this->types[$type->id()] = $type;
        }
    }

    public function has(string $id): bool
    {
        return isset($this->types[$id]);
    }

    public function get(string $id): SourceTypeInterface
    {
        return $this->types[$id] ?? throw new SourceException(\sprintf('Type de source inconnu : "%s".', $id));
    }

    /** @return array<string, SourceTypeInterface> */
    public function all(): array
    {
        return $this->types;
    }

    /** @return array<string, mixed> */
    public function describe(SourceTypeInterface $type): array
    {
        return [
            'id' => $type->id(),
            'name' => $type->name(),
            'icon' => $type->icon(),
            'description' => $type->description(),
            'capabilities' => array_map(static fn (string $view) => ['id' => $view, 'label' => SourceViews::VIEWS[$view] ?? $view], $type->capabilities()),
            'fields' => $type->fields(),
        ];
    }
}
