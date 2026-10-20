<?php

namespace Bchalier\LaravelOpenapiDoc\App\DTO;

/**
 * Lightweight DTO to describe a relationship for documentation purposes.
 * - resources: class-strings of Domain\\Contracts\\ApiResource. The generator will use their static type(). A linkage
 *   that may point to resources of several types lists each of them.
 * - collection: true for to-many, false for to-one.
 */
class RelationshipDoc
{
    /**
     * @param list<class-string<\\Domain\\Contracts\\ApiResource>> $resources
     */
    public function __construct(
        public array $resources = [],
        public bool $collection = false,
    ) {}

    /**
     * Describe a to-many relationship.
     */
    public static function many(string ...$resources): self
    {
        return new self(array_values($resources), true);
    }

    /**
     * Describe a to-one relationship.
     */
    public static function one(string ...$resources): self
    {
        return new self(array_values($resources), false);
    }

    /**
     * Allow downstream code to normalize easily.
     * @return array{resources: list<class-string<\\Domain\\Contracts\\ApiResource>>, collection: bool}
     */
    public function toArray(): array
    {
        return [
            'resources' => $this->resources,
            'collection' => $this->collection,
        ];
    }
}
