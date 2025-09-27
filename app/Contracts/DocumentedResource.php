<?php

namespace Bchalier\LaravelOpenapiDoc\App\Contracts;

use Bchalier\LaravelOpenapiDoc\App\DTO\RelationshipDoc;

interface DocumentedResource
{
    /**
     * Return attributes only, with sample values, without hitting DB/auth.
     * Keys must mirror the runtime resource attributes.
     *
     * May return an associative array of attributes, or a DTO object exposing
     * public properties and/or a toArray() method (e.g., Modules\Auth\Domain\DTO\UserData).
     *
     * @return object
     */
    public function documentationAttributes(): object;

    /**
     * Describe relationships for documentation: name => RelationshipDoc
     * - resource: class-string of a Domain\\Contracts\\ApiResource (DTO) implementing static type().
     *             The generator will use that type() value for JSON:API type examples.
     * - collection: true for to-many, false for to-one.
     *
     * @return array<string, RelationshipDoc>
     */
    public function documentationRelationships(): array;
}
