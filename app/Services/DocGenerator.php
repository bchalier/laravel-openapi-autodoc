<?php

namespace Bchalier\LaravelOpenapiDoc\App\Services;

use App\Http\Controllers\Controller;
use App\Http\Resources\JsonApiResource;
use Bchalier\LaravelOpenapiDoc\App\Exceptions\JsonResourceCollectionNoResource;
use Bchalier\LaravelOpenapiDoc\App\Exceptions\JsonResourceNoFactory;
use Bchalier\LaravelOpenapiDoc\App\Exceptions\JsonResourceNoType;
use Bchalier\LaravelOpenapiDoc\App\Exceptions\ResponseTypeNotSupported;
use Doctrine\Common\Annotations\PhpParser;
use Domain\Contracts\ApiResource;
use GoldSpecDigital\ObjectOrientedOAS\Exceptions\InvalidArgumentException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use GoldSpecDigital\ObjectOrientedOAS\Objects\{Info as OASInfo,
    Operation as OASOperation,
    Parameter as OASParameter,
    PathItem as OASPathItem,
    PathItem,
    Schema as OASSchema,
    Tag as OASTag};
use GoldSpecDigital\ObjectOrientedOAS\OpenApi;
use GoldSpecDigital\ObjectOrientedOAS\Objects\Components as OASComponents;
use Illuminate\Http\Resources\Json\ResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Bchalier\LaravelOpenapiDoc\App\Contracts\DocumentedResource;
// Removed dependency on application-specific exceptions
use phpDocumentor\Reflection\DocBlock;
use phpDocumentor\Reflection\DocBlockFactory;
use ReflectionClass;
use ReflectionException;
use RuntimeException;
use Throwable;

// Removed stray function import

class DocGenerator
{
    use Concerns\Request;
    use Concerns\Responses;

    protected DocParser $parser;
    protected DocBlockFactory $docBlockFactory;
    protected array $operationsByUri;
    protected array $models;
    protected array $tags = [];
    protected array $componentSchemas = [];
    /** @var callable|null */
    protected $routeFilter = null;
    /**
     * Tag groups for vendor extension x-tagGroups
     * Format: ['ModuleName' => ['TagA','TagB',...]]
     * @var array<string, array<int, string>>
     */
    protected array $tagGroups = [];

    /**
     * DocGenerator constructor.
     *
     * @param Router $router
     */
    public function __construct(Router $router)
    {
        $this->parser = new DocParser($router);
        $this->docBlockFactory = DocBlockFactory::createInstance();
    }

    /**
     * Optionally filter routes to include in this document.
     * @param callable|null $filter function(Route): bool
     * @return $this
     */
    public function setRouteFilter(?callable $filter): self
    {
        $this->routeFilter = $filter;
        return $this;
    }

    /**
     * @return OpenApi
     * @throws ResponseTypeNotSupported
     * @throws JsonResourceNoType
     * @throws ReflectionException
     * @throws InvalidArgumentException
     */
    public function generate(): OpenApi
    {
        $this->startTransactions();

        $doc = OpenApi::create()
            ->openapi(OpenApi::OPENAPI_3_0_2)
            ->info($this->getInfo())
            ->paths(...$this->getPaths())
            ->components($this->buildComponents())
            ->tags(...array_values($this->tags))
            ->x('x-tagGroups', $this->formatTagGroups());

        $this->rollback();

        return $doc;
    }

    /**
     * @return OASInfo
     */
    protected function getInfo(): OASInfo
    {
        $config = config('documentation');

        return OASInfo::create()
            ->title($config['title'])
            ->version($config['version'])
            ->description($config['description']);
    }

    /**
     * @return array
     * @throws ResponseTypeNotSupported
     * @throws JsonResourceNoType
     * @throws ReflectionException
     * @throws InvalidArgumentException
     */
    protected function getPaths(): array
    {
        $paths = [];

        /** @var Route $route */
        foreach ($this->parser->getRoutes() as $route) {
            dump("doing route {$route->uri}");

            if ($route->getActionMethod() === 'Closure') {
                continue;
            }

            if ($this->routeFilter && !call_user_func($this->routeFilter, $route)) {
                continue;
            }

            if (!$this->inWhiteList($route)) {
                continue;
            }

            try {
                $paths = $this->addPath($paths, $this->getPath($route));
            } catch (Throwable $e) {
                throw $e;
            }
        }

        return $paths;
    }

    private function addPath(array $paths, PathItem $path): array
    {
        if (isset($paths[$path->route])) {
            $paths[$path->route] = $paths[$path->route]->operations(...array_merge($paths[$path->route]->operations ?? [], $path->operations ?? []));
        } else {
            $paths[$path->route] = $path;
        }

        return $paths;
    }

    /**
     * @param Route $route
     * @return OASPathItem
     * @throws ResponseTypeNotSupported
     * @throws JsonResourceNoType
     * @throws ReflectionException
     * @throws InvalidArgumentException
     */
    protected function getPath(Route $route): OASPathItem
    {
        return OASPathItem::create()
            ->route('/' . $route->uri())
            ->operations(...$this->getOperations($route))
            ->parameters(...$this->getPathParameters($route));
    }

    /**
     * @param Route $route
     * @return array
     * @throws ResponseTypeNotSupported
     * @throws JsonResourceNoType
     * @throws ReflectionException
     * @throws InvalidArgumentException
     */
    protected function getOperations(Route $route): array
    {
        $operations = $this->getCachedOperations($route);
        $controllerReflection = $this->getControllerReflection($route);
        $ignoredVerbs = config('documentation.ignoredVerbs');

        if (!$controllerReflection->hasMethod($route->getActionMethod())) {
            return [];
        }

        $summary = $this->getSummary($controllerReflection, $route->getActionMethod());
        $description = $this->getDescription($controllerReflection, $route->getActionMethod());

        $allowed = ['GET','POST','PUT','PATCH','DELETE','OPTIONS','HEAD'];
        foreach ($route->methods() as $method) {
            if (in_array($method, $ignoredVerbs)) {
                continue;
            }
            if (!in_array(strtoupper($method), $allowed, true)) {
                continue;
            }

            $controller = $route->getController();
            if ($controller) {
                $request = $this->parser->getRequest($route);

                // Determine tag name and module group for x-tagGroups
                $rawTag = $this->getNameFromController($controller);
                $tagName = ucfirst($rawTag);
                $class = ltrim(get_class($controller), '\\');
                $module = 'General';
                if (str_starts_with($class, 'Modules\\')) {
                    $parts = explode('\\', $class);
                    if (isset($parts[1]) && $parts[1] !== '') {
                        $module = $parts[1];
                    }
                }
                $this->addTagToGroup($module, $tagName);

                $operations[] = OASOperation::{strtolower($method)}()
                    ->requestBody($this->getRequestBody($request))
                    ->parameters(...$this->getRequestQueryParameters($request))
                    ->responses(...$this->getResponses($route, $method !== 'HEAD'))
                    ->tags($this->getTag($this->getNameFromController($controller)))
                    ->summary($summary)
                    ->description($description)
                    ->operationId($this->operationId($route, $method));
            }
        }

        return $this->operationsByUri[$route->uri()] = $operations;
    }

    /**
     * @param Route $route
     * @return array
     */
    protected function getCachedOperations(Route $route): array
    {
        return $this->operationsByUri[$route->uri()] ?? [];
    }

    /**
     * @param ReflectionClass $reflection
     * @param                  $method
     * @return string
     * @throws ReflectionException
     */
    protected function getSummary(ReflectionClass $reflection, $method): string
    {
        $docBlock = $this->getDocBlock($reflection, $method);

        return $docBlock ? $docBlock->getSummary() : '';
    }

    /**
     * @param ReflectionClass $reflection
     * @param                  $method
     * @return DocBlock|null
     * @throws ReflectionException
     */
    protected function getDocBlock(ReflectionClass $reflection, $method): ?DocBlock
    {
        $methodReflection = $reflection->getMethod($method);
        $docComment = $methodReflection->getDocComment();

        return is_string($docComment) ? $this->docBlockFactory->create($docComment) : null;
    }

    /**
     * @param Route $route
     * @return ReflectionClass
     * @throws ReflectionException
     */
    protected function getControllerReflection(Route $route): ReflectionClass
    {
        return new ReflectionClass($route->getController());
    }

    /**
     * @param ReflectionClass $reflection
     * @param                  $method
     * @return DocBlock\Description|string
     * @throws ReflectionException
     */
    protected function getDescription(ReflectionClass $reflection, $method): string
    {
        $docBlock = $this->getDocBlock($reflection, $method);

        return $docBlock ? $docBlock->getDescription() : '';
    }

    /**
     * @param $name
     * @return OASTag
     */
    protected function getTag($name): OASTag
    {
        $nameLower = strtolower($name);

        return $this->tags[$nameLower] ?? $this->tags[$nameLower] = OASTag::create()
                ->name(ucfirst($name))
                ->description("All $nameLower related endpoints");
    }

    /**
     * @param Controller $controller
     * @return string
     * @throws ReflectionException
     */
    protected function getNameFromController(object $controller): string
    {
        $shortName = (new ReflectionClass($controller))->getShortName();

        return Str::replaceLast('Controller', '', $shortName);
    }

    /**
     * @param Route $route
     * @return array
     */
    protected function getParameters(Route $route): array
    {
        $parameters = [];

        $parameters[] = OASParameter::create('id')
            ->in(OASParameter::IN_QUERY)
            ->name('test')
            ->example('test !')
            ->description('description');

        return $parameters;
    }

    protected function getPathParameters(Route $route): array
    {
        $parameters = [];
        preg_match_all('/\{([^}]+)\}/', $route->uri(), $matches);
        foreach ($matches[1] ?? [] as $name) {
            // Remove optional markers and patterns, e.g. {id?}
            $clean = rtrim($name, '?');
            $parameters[] = OASParameter::create($clean)
                ->in(OASParameter::IN_PATH)
                ->name($clean)
                ->required(true)
                ->description('Path parameter');
        }

        return $parameters;
    }

    /**
     * @param ReflectionClass $class
     * @return array
     */
    protected function getClassImports(ReflectionClass $class): array
    {
        return (new PhpParser())->parseClass($class);
    }

    /**
     * @param JsonResource $resource
     * @return OASSchema|null
     * @throws ResponseTypeNotSupported
     */
    protected function schemaFromResource(JsonResource $resource, bool $attributesOnly = false): ?OASSchema
    {
        if ($resource instanceof ResourceCollection) {
            $collectionSchema = $this->schemaFromResourceCollection($resource);
            if ($collectionSchema) {
                return $collectionSchema;
            }
        }

        $req = Request::create('/', 'GET');
        $req->headers->set('Accept', 'application/vnd.api+json');
        try {
            if ($attributesOnly) {
                throw new RuntimeException('attributes-only');
            }
            $response = $resource->toResponse($req)->getContent();
            return $this->extractFromArray(json_decode($response, true));
        } catch (Throwable $e) {
            // Fallback for resources with heavy relationship logic: only document attributes
            try {
                $ref = new ReflectionClass($resource);
                // 0) Explicit doc hook on the Resource itself
                if ($ref->hasMethod('documentationAttributes')) {
                    $m = $ref->getMethod('documentationAttributes');
                    $m->setAccessible(true);
                    $raw = $m->invoke($resource);
                    $attributes = $this->documentedAttributesToArray($resource, $raw);
                    $schemas = [];
                    foreach ($attributes as $k => $v) {
                        $schemas[] = $this->schemaFromValue($k, $v);
                    }
                    if ($resource instanceof JsonApiResource) {
                        $dataSchema = OASSchema::object('data')->properties(
                            OASSchema::string('id')->example('id'),
                            OASSchema::string('type')->example('type'),
                            OASSchema::object('attributes')->properties(...$schemas)
                        );
                        return OASSchema::object()->properties($dataSchema);
                    }

                    $base = $this->resourceBaseName($resource);
                    $attributesName = $base . 'Attributes';
                    $this->registerSchema($attributesName, OASSchema::object($attributesName)->properties(...$schemas));
                    return OASSchema::ref('#/components/schemas/' . $attributesName);

                }
                if ($ref->hasMethod('toAttributes')) {
                    $m = $ref->getMethod('toAttributes');
                    $m->setAccessible(true);
                    $attributes = (array) $m->invoke($resource, $req);
                    $schemas = [];
                    foreach ($attributes as $k => $v) {
                        $schemas[] = $this->schemaFromValue($k, $v);
                    }
                    if ($resource instanceof JsonApiResource) {
                        $dataSchema = OASSchema::object('data')->properties(
                            OASSchema::string('id')->example('id'),
                            OASSchema::string('type')->example('type'),
                            OASSchema::object('attributes')->properties(...$schemas)
                        );
                        return OASSchema::object()->properties($dataSchema);
                    }


                    $base = $this->resourceBaseName($resource);
                    $attributesName = $base . 'Attributes';
                    $this->registerSchema($attributesName, OASSchema::object($attributesName)->properties(...$schemas));
                    return OASSchema::ref('#/components/schemas/' . $attributesName);

                }

                // As a last resort, inspect the payload object public properties
                if ($ref->hasProperty('resource')) {
                    $prop = $ref->getProperty('resource');
                    $prop->setAccessible(true);
                    $payload = $prop->getValue($resource);
                    if (is_object($payload)) {
                        $schemas = [];
                        foreach (get_object_vars($payload) as $k => $v) {
                            $schemas[] = $this->schemaFromValue($k, $v);
                        }

                        if ($resource instanceof JsonApiResource) {
                            $dataSchema = OASSchema::object('data')->properties(
                                OASSchema::string('id')->example('id'),
                                OASSchema::string('type')->example('type'),
                                OASSchema::object('attributes')->properties(...$schemas)
                            );
                            return OASSchema::object()->properties($dataSchema);
                        }


                    $base = $this->resourceBaseName($resource);
                    $attributesName = $base . 'Attributes';
                    $this->registerSchema($attributesName, OASSchema::object($attributesName)->properties(...$schemas));
                    return OASSchema::ref('#/components/schemas/' . $attributesName);

                    }
                }
            } catch (Throwable $_) {
                // ignore and return null
            }
        }

        if ($resource instanceof JsonApiResource) {
            $dataSchema = OASSchema::object('data')->properties(
                OASSchema::object('attributes')
            );
            return OASSchema::object()->properties($dataSchema);
        }

        return null;
    }

    /**
     * @param array|null $array
     * @return OASSchema|null
     */
    protected function extractFromArray(?array $array): ?OASSchema
    {
        if (is_null($array) || empty($array)) {
            return null;
        }

        $schemas = [];

        foreach ($array as $key => $property) {
            $schemas[] = $this->schemaFromValue($key, $property);
        }

        return (new OASSchema())->properties(...$schemas);
    }

    /**
     * @param string $key
     * @param mixed $value
     * @return OASSchema
     */
    protected function schemaFromValue(string $key, mixed $value): OASSchema
    {
        $normalized = $this->normalizeSchemaValue($value);
        if ($normalized !== $value) {
            return $this->schemaFromValue($key, $normalized);
        }

        if ($value instanceof Collection) {
            $itemSchema = $this->schemaForArrayItems($value->all());
            return OASSchema::array($key)->items($itemSchema);
        }

        if ($value instanceof JsonResource) {
            return $this->getSchemaFromResource($value)->objectId($key);
        }

        if (is_array($value)) {
            return $this->extractSchemaFromArray($key, $value);
        }

        if (is_object($value)) {
            $properties = $this->extractPropertiesFromArray(get_object_vars($value));
            return OASSchema::object($key)->properties(...$properties);
        }

        return $this->extractSchemaFromProperty($key, $value);
    }

    /**
     * @param mixed $value
     * @return mixed
     */
    protected function normalizeSchemaValue(mixed $value): mixed
    {
        if (!is_object($value)) {
            return $value;
        }

        if ($value instanceof \BackedEnum) {
            return $value->value;
        }

        if ($value instanceof \UnitEnum) {
            return $value->name;
        }

        if ($value instanceof \JsonSerializable) {
            try {
                $serialized = $value->jsonSerialize();
                if (is_array($serialized) || is_scalar($serialized) || $serialized === null) {
                    return $serialized;
                }
            } catch (Throwable) {
            }
        }

        if (method_exists($value, 'toArray')) {
            try {
                $array = $value->toArray();
                if (is_array($array)) {
                    return $array;
                }
            } catch (Throwable) {
            }
        }

        if ($value instanceof \Stringable) {
            return (string) $value;
        }

        $props = get_object_vars($value);
        if (!empty($props)) {
            return $props;
        }

        return $value;
    }

    public function schemaForDocumentedResource(JsonResource $resource): OASSchema
    {
        $base = $this->resourceBaseName($resource);
        $resourceName = $base . 'Resource';

        $dataSchema = $this->jsonApiDataSchemaForDocumentedResource($resource);
        $this->registerSchema($resourceName, OASSchema::object($resourceName)->properties($dataSchema));

        return OASSchema::ref('#/components/schemas/' . $resourceName);
    }

    /**
     * Build the JSON:API data object schema for a documented resource.
     */
    protected function jsonApiDataSchemaForDocumentedResource(JsonResource $resource): OASSchema
    {
        $base = $this->resourceBaseName($resource);
        $attributesName = $base . 'Attributes';

        $attributes = [];
        if (method_exists($resource, 'documentationAttributes')) {
            try {
                $raw = $resource->documentationAttributes();
                $attributes = $this->documentedAttributesToArray($resource, $raw);
            } catch (Throwable) {
                $attributes = [];
            }
        }

        $attrSchemas = [];
        foreach ($attributes as $k => $v) {
            $attrSchemas[] = $this->schemaFromValue($k, $v);
        }
        $this->registerSchema($attributesName, OASSchema::object($attributesName)->properties(...$attrSchemas));

        $dataProps = [
            OASSchema::string('id')->example('id'),
            OASSchema::string('type')->example(Str::camel(Str::plural($base))),
            OASSchema::ref('#/components/schemas/' . $attributesName)->objectId('attributes'),
        ];

        if (method_exists($resource, 'documentationRelationships')) {
            $rels = (array) $resource->documentationRelationships();
            if (!empty($rels)) {
                $relationshipProps = [];
                foreach ($rels as $name => $meta) {
                    $rel = $this->normalizeRelationshipMeta($meta, (string) $name);
                    $relSchemaName = $base . 'Relationship' . Str::studly($name);
                    $targetBase = $rel['resource'] ?? (string) $name;
                    $typeExample = $rel['type'] ?? Str::camel(Str::plural($targetBase));

                    // Build identifier object with correct type + uuid id
                    $identifierObject = OASSchema::object('data')->properties(
                        OASSchema::string('type')->example($typeExample),
                        OASSchema::string('id')->format(OASSchema::FORMAT_UUID)->example('00000000-0000-0000-0000-000000000000')
                    );

                    $relDataSchema = (!empty($rel['collection']))
                        ? OASSchema::array('data')->items(
                            OASSchema::object()->properties(
                                OASSchema::string('type')->example($typeExample),
                                OASSchema::string('id')->format(OASSchema::FORMAT_UUID)->example('00000000-0000-0000-0000-000000000000')
                            )
                        )
                        : $identifierObject;

                    $this->registerSchema($relSchemaName, OASSchema::object($relSchemaName)->properties($relDataSchema));
                    $relationshipProps[] = OASSchema::ref('#/components/schemas/' . $relSchemaName)->objectId($name);
                }
                $dataProps[] = OASSchema::object('relationships')->properties(...$relationshipProps);
            }
        }

        return OASSchema::object('data')->properties(...$dataProps);
    }

    /**
     * @param ResourceCollection $collection
     * @return OASSchema|null
     */
    protected function schemaFromResourceCollection(ResourceCollection $collection): ?OASSchema
    {
        $collects = $this->resolveCollectedResourceClass($collection);
        if (!$collects || !class_exists($collects)) {
            return null;
        }

        try {
            $resource = new $collects(null);
        } catch (Throwable) {
            return null;
        }

        if (!$resource instanceof JsonResource) {
            return null;
        }

        if ($resource instanceof DocumentedResource) {
            $dataSchema = $this->jsonApiDataSchemaForDocumentedResource($resource);
            return OASSchema::object()->properties(
                OASSchema::array('data')->items($dataSchema)
            );
        }

        return null;
    }

    /**
     * Resolve the JsonResource class collected by a ResourceCollection.
     */
    protected function resolveCollectedResourceClass(ResourceCollection $collection): ?string
    {
        if (is_string($collection->collects) && $collection->collects !== '') {
            if (is_subclass_of($collection->collects, JsonResource::class)) {
                return $collection->collects;
            }
            return null;
        }

        $base = class_basename($collection);
        if (str_ends_with($base, 'Collection')) {
            $class = Str::replaceLast('Collection', '', get_class($collection));
            if (class_exists($class) && is_subclass_of($class, JsonResource::class)) {
                return $class;
            }
            $class = Str::replaceLast('Collection', 'Resource', get_class($collection));
            if (class_exists($class) && is_subclass_of($class, JsonResource::class)) {
                return $class;
            }
        }

        return null;
    }

    /**
     * Normalize relationship doc metadata to a simple associative array.
     * Accepts arrays, stdClass, or DTO-like objects exposing properties or accessors.
     *
     * @param mixed $meta
     * @param string $fallbackName
     * @return array{resource?: string, collection?: bool}
     */
    protected function normalizeRelationshipMeta(mixed $meta, string $fallbackName): array
    {
        // Internal: resolve a class-string into ['type' => string] if it implements ApiResource
        $resolveApiResource = function (?string $class): ?array {
            if (is_string($class) && class_exists($class) && is_subclass_of($class, ApiResource::class)) {
                try {
                    $type = $class::type();
                    if (is_string($type) && $type !== '') {
                        return ['type' => $type];
                    }
                } catch (Throwable) {}
            }
            return null;
        };

        // Accept an array (e.g., from RelationshipDoc::toArray)
        if (is_array($meta)) {
            $out = [];
            $collection = $meta['collection'] ?? null;
            $resource = $meta['resource'] ?? null;
            if ($collection !== null) { $out['collection'] = (bool) $collection; }
            if (is_string($resource)) {
                if ($resolved = $resolveApiResource($resource)) {
                    return array_merge($out, $resolved);
                }
            }
            // Fallback: no valid ApiResource provided
            return array_merge(['collection' => (bool) ($collection ?? false), 'type' => Str::camel(Str::plural($fallbackName))], $out);
        }

        // If object with toArray()
        if (is_object($meta) && method_exists($meta, 'toArray')) {
            $arr = (array) $meta->toArray();
            return $this->normalizeRelationshipMeta($arr, $fallbackName);
        }

        // If meta itself is a class-string
        if (is_string($meta)) {
            if ($resolved = $resolveApiResource($meta)) {
                return array_merge(['collection' => false], $resolved);
            }
        }

        // Default fallback: single relationship with inferred type from name
        return ['collection' => false, 'type' => Str::camel(Str::plural($fallbackName))];
    }

    /**
     * @param $key
     * @param $object
     * @return OASSchema
     * @throws ResponseTypeNotSupported
     */
    protected function extractSchemaFromObject($key, $object): OASSchema
    {
        if ($object instanceof Collection) {
            $properties = $this->extractSchemaFromCollection($object);
        } elseif ($object instanceof JsonResource) {
            $properties = $this->getSchemaFromResource($object);
        } else {
            throw new ResponseTypeNotSupported($object);
        }

        return OASSchema::object($key)
            ->type(OASSchema::TYPE_ARRAY)
            ->items(Arr::first($properties));
    }

    /**
     * @param Collection $collection
     * @return array
     */
    protected function extractSchemaFromCollection(Collection $collection): array
    {
        $resources = [];

        foreach ($collection as $item) {
            $resources[] = $this->getSchemaFromResource($item);
        }

        return $resources;
    }

    /**
     * @param JsonResource $resource
     * @return OASSchema
     */
    protected function getSchemaFromResource(JsonResource $resource): OASSchema
    {
        return $this->models[$this->getResourceCacheKey($resource)] ?? $this->extractSchemaFromResource($resource);
    }

    /**
     * @param JsonResource $resource
     * @return string
     */
    protected function getResourceCacheKey(JsonResource $resource): string
    {
        return get_class($resource);
    }

    /**
     * @param JsonResource $resource
     * @return OASSchema
     */
    protected function extractSchemaFromResource(JsonResource $resource): OASSchema
    {
        $properties = $this->extractPropertiesFromArray($resource->toArray(null));

        return $this->models[$this->getResourceCacheKey($resource)] = OASSchema::object()->properties(...$properties);
    }

    /**
     * @param array $propertiesList
     * @return array
     */
    protected function extractPropertiesFromArray(array $propertiesList): array
    {
        $schemaList = [];

        foreach ($propertiesList as $key => $property) {
            $schemaList[] = $this->schemaFromValue($key, $property);
        }

        return $schemaList;
    }

    protected function extractType($value): string
    {
        return match (gettype($value)) {
            'double' => OASSchema::TYPE_NUMBER,
            'integer' => OASSchema::TYPE_INTEGER,
            'boolean' => OASSchema::TYPE_BOOLEAN,
            'array' => OASSchema::TYPE_ARRAY,
            'object' => OASSchema::TYPE_OBJECT,
            default => OASSchema::TYPE_STRING,
        };
    }

    /**
     * @param $key
     * @param $property
     * @return OASSchema
     */
    protected function extractSchemaFromProperty($key, $property): OASSchema
    {
        $type = $this->extractType($property);

        $schema = OASSchema::$type($key);

        if ($property === null) {
            return $schema->nullable(true)->example(null);
        }

        return $schema->example($property);
    }

    /**
     * @param $key
     * @param $property
     * @return OASSchema
     */
    protected function extractSchemaFromArray($key, $property): OASSchema
    {
        if (Arr::isAssoc($property)) {
            return OASSchema::object($key)
                ->properties(...$this->extractPropertiesFromArray($property));
        }

        $itemSchema = $this->schemaForArrayItems($property);

        return OASSchema::array($key)->items($itemSchema);
    }

    /**
     * @param array $items
     * @return OASSchema
     */
    protected function schemaForArrayItems(array $items): OASSchema
    {
        foreach ($items as $item) {
            if ($item !== null) {
                return $this->schemaFromValue('item', $item);
            }
        }

        return OASSchema::object('item');
    }

    protected function inWhiteList(Route $route): bool
    {
        $whiteList = config('documentation.uriWhiteList');

        foreach ($whiteList as $rule) {
            if (fnmatch($rule, $route->uri())) {
                return true;
            }
        }

        return false;
    }

    protected function operationId(Route $route, string $method): string
    {
        $name = $route->getName();
        if (is_string($name) && $name !== '') {
            return $name . ".$method";
        }

        $uri = trim($route->uri(), '/');
        $uri = $uri === '' ? 'root' : str_replace(['/', '{', '}', ':'], ['_', '', '', '_'], $uri);
        return strtolower($method . '_' . $uri);
    }

    private function startTransactions()
    {
        foreach (config('documentation.connections_to_transact') as $connection) {
            DB::connection($connection)->beginTransaction();
        }
    }

    private function rollback()
    {
        foreach (config('documentation.connections_to_transact') as $connection) {
            DB::connection($connection)->rollBack();
        }
    }

    protected function registerSchema(string $name, OASSchema $schema): void
    {
        $this->componentSchemas[$name] = $schema->objectId($name);
    }

    protected function buildComponents(): OASComponents
    {
        return OASComponents::create()->schemas(...array_values($this->componentSchemas));
    }

    protected function addTagToGroup(string $group, string $tag): void
    {
        if (!isset($this->tagGroups[$group])) {
            $this->tagGroups[$group] = [];
        }
        if (!in_array($tag, $this->tagGroups[$group], true)) {
            $this->tagGroups[$group][] = $tag;
        }
    }

    /**
     * Format x-tagGroups vendor extension for ReDoc: [{ name, tags: [] }, ...]
     * @return array<int, array{name: string, tags: array<int,string>}>
     */
    protected function formatTagGroups(): array
    {
        $groups = [];
        foreach ($this->tagGroups as $name => $tags) {
            sort($tags);
            $groups[] = ['name' => $name, 'tags' => array_values($tags)];
        }
        // Ensure stable ordering by group name
        usort($groups, fn($a, $b) => strcmp($a['name'], $b['name']));
        return $groups;
    }

    protected function resourceBaseName(JsonResource $resource): string
    {
        $short = (new ReflectionClass($resource))->getShortName();
        return Str::replaceLast('Resource', '', $short);
    }

    protected function ensureResourceIdentifierSchema(): void
    {
        if (!isset($this->componentSchemas['ResourceIdentifier'])) {
            $this->registerSchema('ResourceIdentifier', OASSchema::object('ResourceIdentifier')->properties(
                OASSchema::string('type')->example('resource'),
                OASSchema::string('id')->example('id')
            ));
        }
    }

    /**
     * Normalize documentation attributes into an associative array.
     * Accepts plain arrays or DTO-like objects with toArray() or public properties.
     *
     * @param mixed $attrs
     * @return array<string, mixed>
     */
    protected function normalizeDocAttributes(mixed $attrs): array
    {
        if (is_array($attrs)) {
            return $attrs;
        }
        if (is_object($attrs)) {
            try {
                if (method_exists($attrs, 'toArray')) {
                    $arr = $attrs->toArray();
                    if (is_array($arr)) {
                        return $arr;
                    }
                }
            } catch (Throwable) {}
            try {
                return get_object_vars($attrs);
            } catch (Throwable) {}
        }
        return [];
    }

    /**
     * @param JsonResource $resource
     * @param mixed $raw
     * @return array<string, mixed>
     */
    protected function documentedAttributesToArray(JsonResource $resource, mixed $raw): array
    {
        $rawAttributes = $this->normalizeDocAttributes($raw);

        try {
            $ref = new ReflectionClass($resource);
            if ($ref->hasMethod('toAttributes')) {
                $method = $ref->getMethod('toAttributes');
                $method->setAccessible(true);
                $clone = clone $resource;
                $clone->resource = $raw;
                $attributes = $method->invoke($clone, Request::create('/', 'GET'));
                if (is_array($attributes)) {
                    return $attributes;
                }
            }
        } catch (Throwable $e) {
            if (isset($method) && $method instanceof \ReflectionMethod) {
                $keys = $this->extractAttributeKeysFromMethod($method);
                if (!empty($keys)) {
                    return $this->mapAttributeKeysToRaw($keys, $rawAttributes);
                }
            }
        }

        return $rawAttributes;
    }

    /**
     * @param \ReflectionMethod $method
     * @return array<int, string>
     */
    protected function extractAttributeKeysFromMethod(\ReflectionMethod $method): array
    {
        $file = $method->getFileName();
        if (!is_string($file) || $file === '') {
            return [];
        }

        $start = $method->getStartLine();
        $end = $method->getEndLine();
        if ($start <= 0 || $end <= 0 || $end < $start) {
            return [];
        }

        $lines = @file($file, FILE_IGNORE_NEW_LINES);
        if (!is_array($lines)) {
            return [];
        }

        $slice = array_slice($lines, $start - 1, $end - $start + 1);
        $source = implode("\n", $slice);

        if (!preg_match_all('/[\'"]([^\'"]+)[\'"]\s*=>/', $source, $matches)) {
            return [];
        }

        return array_values(array_unique($matches[1]));
    }

    /**
     * @param array<int, string> $keys
     * @param array<string, mixed> $rawAttributes
     * @return array<string, mixed>
     */
    protected function mapAttributeKeysToRaw(array $keys, array $rawAttributes): array
    {
        $mapped = [];
        foreach ($keys as $key) {
            if (array_key_exists($key, $rawAttributes)) {
                $mapped[$key] = $rawAttributes[$key];
                continue;
            }
            $camel = Str::camel($key);
            if (array_key_exists($camel, $rawAttributes)) {
                $mapped[$key] = $rawAttributes[$camel];
                continue;
            }
            $mapped[$key] = null;
        }

        return $mapped;
    }
}
