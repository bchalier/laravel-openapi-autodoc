<?php

namespace Bchalier\LaravelOpenapiDoc\App\DTO;

/**
 * Lightweight DTO to describe a relationship for documentation purposes.
 * - resource: class-string of a Domain\\Contracts\\ApiResource. The generator will use its static type().
 * - collection: true for to-many, false for to-one.
 */
class RelationshipDoc
{
    public function __construct(
        public ?string $resource = null,
        public bool $collection = false,
    ) {}

    /**
     * Describe a to-many relationship.
     */
    public static function many(?string $resource = null): self
    {
        return new self($resource, true);
    }

    /**
     * Describe a to-one relationship.
     */
    public static function one(?string $resource = null): self
    {
        return new self($resource, false);
    }

    /**
     * Allow downstream code to normalize easily.
     * @return array{resource: (class-string<\\Domain\\Contracts\\ApiResource>)|null, collection: bool}
     */
    public function toArray(): array
    {
        return [
            'resource' => $this->resource,
            'collection' => $this->collection,
        ];
    }
}
