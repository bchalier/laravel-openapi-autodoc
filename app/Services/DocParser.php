<?php

namespace Bchalier\LaravelOpenapiDoc\App\Services;

use Bchalier\LaravelOpenapiDoc\App\Exceptions\JsonResourceCollectionNoResource;
use Bchalier\LaravelOpenapiDoc\App\Exceptions\JsonResourceNoFactory;
use Bchalier\LaravelOpenapiDoc\App\Exceptions\JsonResourceNoType;
use Bchalier\LaravelOpenapiDoc\App\Exceptions\ResponseTypeNotSupported;
use Bchalier\LaravelOpenapiDoc\App\Tags\DocForceTypeTag;
use Doctrine\Common\Annotations\PhpParser;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\Json\ResourceCollection;
use Illuminate\Routing\Route;
use Illuminate\Routing\RouteCollection;
use Illuminate\Routing\Router;
use phpDocumentor\Reflection\DocBlockFactory;
use phpDocumentor\Reflection\Types\Compound;
use phpDocumentor\Reflection\Types\Object_;

class DocParser
{
    protected $router;

    /** @var \phpDocumentor\Reflection\DocBlockFactory */
    protected $docBlockFactory;

    protected $customTags = [
        'autodoc-type' => '\\' . DocForceTypeTag::class,
    ];

    /**
     * DocParser constructor.
     *
     * @param Router $router
     */
    public function __construct(Router $router)
    {
        $this->router = $router;
        $this->docBlockFactory = DocBlockFactory::createInstance($this->customTags);
    }

    /**
     * @return RouteCollection
     */
    public function getRoutes(): RouteCollection
    {
        return $this->router->getRoutes();
    }

    /**
     * @param Route $route
     * @return array
     * @throws JsonResourceNoType
     * @throws ResponseTypeNotSupported
     * @throws \ReflectionException
     */
    public function getResponses(Route $route): array
    {
        $responses = [];

        if ($response = $this->getDefaultResponse($route)) {
            $responses[] = $response;
        }

        return $responses;
    }

    /**
     * Find the default response (usually a resource).
     *
     * @param Route $route
     * @return object
     * @throws JsonResourceNoType
     * @throws ResponseTypeNotSupported
     * @throws \ReflectionException
     */
    protected function getDefaultResponse(Route $route): ?object
    {
        $returnType = $this->getReturnType($route);
        $responseClassName = $returnType?->getName() ?? $this->getReturnClassFromDocblock($route);

        if (!class_exists($responseClassName)) {
            return null;
        }

        $responseClassReflection = new \ReflectionClass($responseClassName);
        $responseClassInstance = $responseClassReflection->newInstanceWithoutConstructor();

        if ($responseClassInstance instanceof ResourceCollection) {
            $responseClass = new $responseClassName($this->getResourceCollectionArguments($responseClassInstance));
        } elseif ($responseClassInstance instanceof JsonResource) {
            $responseClass = new $responseClassName($this->getResourceArguments($responseClassInstance));
        } elseif ($responseClassInstance instanceof JsonResponse) {
            $responseClass = new $responseClassName($this->getResponseArguments($responseClassInstance));
        } elseif ($responseClassInstance instanceof RedirectResponse) {
            $responseClass = new JsonResponse(null, 302);
        } else {
            throw new ResponseTypeNotSupported($responseClassName);
        }

        return $responseClass;
    }

    /**
     * @param Route $route
     * @return \ReflectionNamedType|null
     * @throws \ReflectionException
     */
    protected function getReturnType(Route $route): ?\ReflectionNamedType
    {
        $controller = new \ReflectionClass($route->getController());
        $method = $controller->getMethod($route->getActionMethod());

        return $method->getReturnType();
    }

    /**
     * @param ResourceCollection $resourceCollection
     * @return Collection
     * @throws JsonResourceNoType
     * @throws \ReflectionException
     */
    protected function getResourceCollectionArguments(ResourceCollection $resourceCollection)
    {
        // Return empty collection to allow safe instantiation without DB/DTOs
        return collect();
    }

    /**
     * Find the model of the specified resource collection.
     *
     * @param ResourceCollection $resourceCollection
     * @return string
     * @throws JsonResourceNoType
     * @throws \ReflectionException
     */
    protected function getResourceCollectionModel(ResourceCollection $resourceCollection): string
    {
        $resourceCollectionReflection = new \ReflectionClass($resourceCollection);

        $collectMethod = $resourceCollectionReflection->getMethod('collects');
        $collectMethod->setAccessible(true);
        $resourceClass = $collectMethod->invoke($resourceCollection);

        if (!class_exists($resourceClass)) {
            throw new JsonResourceCollectionNoResource($resourceCollection);
        }

        $resource = (new \ReflectionClass($resourceClass))->newInstanceWithoutConstructor();

        return $this->getResourceModel($resource);
    }

    /**
     * Find the model of the specified resource.
     *
     * @param JsonResource $resource
     * @return string|null
     * @throws JsonResourceNoType
     * @throws \ReflectionException
     */
    protected function getResourceModel(JsonResource $resource): string
    {
        $resourceReflection = new \ReflectionClass($resource);
        $constructMethod = $resourceReflection->getMethod('__construct');

        if ($type = $this->methodTypeFromPhpdoc($constructMethod) ?? method_type($constructMethod) ?? $this->propertyTypeFromPhpdoc($resourceReflection, 'resource')) {
            return ltrim($type, '\\');
        } else {
            throw new JsonResourceNoType($resource);
        }
    }

    protected function methodTypeFromPhpdoc(\ReflectionMethod $method)
    {
        $docComment = $method->getDocComment();

        if (empty($docComment)) {
            return null;
        }

        $docBlock = $this->docBlockFactory->create($docComment);
        $tag = $docBlock->getTagsByName('autodoc-type');

        return empty($tag) ? null : $tag[0]->getType();
    }

    protected function propertyTypeFromPhpdoc(\ReflectionClass $class, string $property): ?string
    {
        $doc = $class->getDocComment();
        if (!$doc) return null;

        $docBlock = $this->docBlockFactory->create($doc);
        $tags = array_merge($docBlock->getTagsByName('property'), $docBlock->getTagsByName('property-read'));

        $imports = (new PhpParser())->parseClass($class);
        foreach ($tags as $tag) {
            /** @var \phpDocumentor\Reflection\DocBlock\Tags\Property $tag */
            if ($tag->getVariableName() !== $property) continue;
            $type = $tag->getType();
            if ($type instanceof Compound) {
                foreach ($type->getTypes() as $t) {
                    if ($t instanceof Object_) {
                        $fqsen = $t->getFqsen();
                        $name = ltrim((string) $fqsen, '\\');
                        if (!class_exists($name)) {
                            $short = ltrim($fqsen?->getName() ?? '', '\\');
                            $name = $imports[strtolower($short)] ?? $name;
                        }
                        if (class_exists($name)) return $name;
                    }
                }
            } elseif ($type instanceof Object_) {
                $fqsen = $type->getFqsen();
                $name = ltrim((string) $fqsen, '\\');
                if (!class_exists($name)) {
                    $short = ltrim($fqsen?->getName() ?? '', '\\');
                    $name = $imports[strtolower($short)] ?? $name;
                }
                if (class_exists($name)) return $name;
            }
        }

        return null;
    }

    protected function getReturnClassFromDocblock(Route $route): ?string
    {
        try {
            $controller = new \ReflectionClass($route->getController());
            $method = $controller->getMethod($route->getActionMethod());
            $doc = $method->getDocComment();
            if (!$doc) return null;

            $docBlock = $this->docBlockFactory->create($doc);
            $tags = $docBlock->getTagsByName('return');
            if (empty($tags)) return null;

            $imports = (new PhpParser())->parseClass($controller);
            foreach ($tags as $tag) {
                /** @var \phpDocumentor\Reflection\DocBlock\Tags\Return_ $tag */
                $type = $tag->getType();
                $candidates = [];
                if ($type instanceof Compound) {
                    foreach ($type->getTypes() as $t) { $candidates[] = $t; }
                } else {
                    $candidates[] = $type;
                }

                foreach ($candidates as $t) {
                    if ($t instanceof Object_) {
                        $fqsen = $t->getFqsen();
                        $class = ltrim((string) $fqsen, '\\');
                        if (!class_exists($class)) {
                            $short = ltrim($fqsen?->getName() ?? '', '\\');
                            $class = $imports[strtolower($short)] ?? $class;
                        }
                        if (class_exists($class)) {
                            return $class;
                        }
                    }
                }
            }
        } catch (\Throwable) {
            return null;
        }

        return null;
    }

    /**
     * @param JsonResource $resource
     * @return Model
     * @throws JsonResourceNoType
     * @throws \ReflectionException
     */
    protected function getResourceArguments(JsonResource $resource)
    {
        // Return null so resources can be constructed without payload and
        // documented via documentationAttributes()/toAttributes() fallback.
        return null;
    }

    /**
     * @param JsonResponse $response
     * @return Model|null
     * @throws \ReflectionException
     */
    protected function getResponseArguments(JsonResponse $response)
    {
        // Avoid resolving DTOs; return null sample
        return null;
    }

    protected function ensureFactoryTraitPresence(string $resource): void
    {
        if (!in_array(HasFactory::class, class_uses_recursive($resource))) {
            throw new JsonResourceNoFactory($resource);
        }
    }

    /**
     * @param JsonResponse $response
     * @return string|null
     * @throws \ReflectionException
     */
    protected function getResponseModel(JsonResponse $response): ?string
    {
        $resourceReflection = new \ReflectionClass($response);
        $constructMethod = $resourceReflection->getMethod('__construct');

        foreach ($constructMethod->getParameters() as $parameter) {
            if ($parameter->hasType()) {
                return $parameter->getType()->getName();
            }
        }

        return null;
    }

    /**
     * @param Route $route
     * @return FormRequest|null
     */
    public function getRequest(Route $route): ?FormRequest
    {
        $routeParameters = $route->signatureParameters();

        /** @var \ReflectionParameter $routeParameter */
        foreach ($routeParameters as $routeParameter) {
            if (!$routeParameter->hasType()) {
                continue;
            }

            if ($routeParameter->getType()->isBuiltin()) {
                continue;
            }

            if (!is_subclass_of($routeParameter->getType()->getName(), FormRequest::class)) {
                continue;
            }

            return tap(new ($routeParameter->getType()->getName()), fn($p) => $p->headers->set('Accept', 'application/vnd.api+json'));
        }

        return null;
    }

    protected function configureModel($model)
    {
        $this->addUuidIfNeeded($model);
        $this->initStateIfNeeded($model);
    }

    protected function addUuidIfNeeded($model)
    {
        if (in_array('Dyrynda\Database\Support\GeneratesUuid', class_uses_recursive(get_class($model)))) {
            $model->uuid = $model->resolveUuid();
        }
    }

    protected function initStateIfNeeded($model)
    {
        if (method_exists($model, 'initState')) {
            $model->initState();
        }
    }
}
