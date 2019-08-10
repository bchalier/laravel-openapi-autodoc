<?php

namespace Bchalier\LaravelOpenapiDoc\App\Services\Concerns;

use Bchalier\LaravelOpenapiDoc\App\Exceptions\ResponseTypeNotSupported;
use GoldSpecDigital\ObjectOrientedOAS\Objects\{MediaType as OASMediaType, Response as OASResponse};
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Routing\Route;
use phpDocumentor\Reflection\Types\Object_;

trait Responses
{
    /**
     * @param Route $route
     * @param bool  $hasBody
     * @return array
     * @throws ResponseTypeNotSupported
     * @throws \Bchalier\LaravelOpenapiDoc\App\Exceptions\JsonResourceNoType
     * @throws \ReflectionException
     */
    protected function getResponses(Route $route, $hasBody = true): array
    {
        $responses = [];

        foreach ($this->parser->getResponses($route) as $response) {
            $responses[] = $this->getResponse($response, $hasBody);
            $responses = array_merge($this->responsesFromPhpdoc($route), $responses);
        }

        return $responses;
    }

    /**
     * @param      $response
     * @param bool $hasBody
     * @return OASResponse|null
     * @throws ResponseTypeNotSupported
     * @throws \ReflectionException
     */
    protected function getResponse($response, $hasBody = true): ?OASResponse
    {
        if ($response instanceof JsonResource) {
            return $this->responseFromResource($response, $hasBody);
        } elseif ($response instanceof JsonResponse) {
            return $this->responseFromResponse($response, $hasBody);
        } else {
            throw new ResponseTypeNotSupported($response);
        }
    }

    /**
     * @param JsonResource $resource
     * @param bool         $hasBody
     * @return OASResponse
     * @throws ResponseTypeNotSupported
     * @throws \ReflectionException
     */
    protected function responseFromResource(JsonResource $resource, $hasBody = true): OASResponse
    {
        $response = $resource->toResponse(null);
        $summary = $this->getSummary(new \ReflectionClass($resource), 'toArray');

        $response = OASResponse::create()
            ->statusCode($response->getStatusCode())
            ->description($summary);

        return $hasBody ? $response->content(
            OASMediaType::json()->schema($this->schemaFromResource($resource))
        ) : $response;
    }

    /**
     * @param JsonResponse $jsonResponse
     * @param bool         $hasBody
     * @return OASResponse
     * @throws ResponseTypeNotSupported
     * @throws \ReflectionException
     */
    protected function responseFromResponse(JsonResponse $jsonResponse, $hasBody = true): OASResponse
    {
        $summary = $this->getSummary(new \ReflectionClass($jsonResponse), '__construct');

        $response = OASResponse::create()
            ->statusCode($jsonResponse->getStatusCode())
            ->description($summary);

        return $hasBody ? $response->content(
            OASMediaType::json()->schema($this->extractFromArray($jsonResponse->getData(true)))
        ) : $response;
    }

    /**
     * @param Route $route
     * @return array
     * @throws ResponseTypeNotSupported
     * @throws \ReflectionException
     */
    protected function responsesFromPhpdoc(Route $route): array
    {
        $errors = $this->getPhpdocErrorsFromRoute($route);
        $responses = [];

        foreach ($errors as $error) {
            $responses[] = $this->responseFromError($error);
        }

        return $responses;
    }

    /**
     * @param Route $route
     * @return array
     * @throws \ReflectionException
     */
    protected function getPhpdocErrorsFromRoute(Route $route): array
    {
        $tags = $this->getDocBlock($this->getControllerReflection($route), $route->getActionMethod())->getTags();
        $classImports = $this->getClassImports($this->getControllerReflection($route));
        $errors = [];

        foreach ($tags as $tag) {
            if ($tag->getName() !== 'throws') continue;

            /** @var $tag \phpDocumentor\Reflection\DocBlock\Tags\Throws */
            $type = $tag->getType();

            if ($type instanceof Object_) {
                $class = $type->getFqsen()->getName();

                if (!class_exists($class)) {
                    $class = $classImports[strtolower($class)];
                }

                if (class_exists($class)) {
                    $errors[] = $class;
                }
            }
        }

        return $errors;
    }

    /**
     * @param string $error
     * @return OASResponse
     * @throws ResponseTypeNotSupported
     * @throws \ReflectionException
     */
    protected function responseFromError(string $error): OASResponse
    {
        $handler = new \App\Exceptions\Handler(app());
        $request = new Request();
        $request->headers->set('Accept', 'application/vnd.api+json');

        $response = $handler->render($request, new $error);

        $content = json_decode($response->getContent(), true);
        $summary = $this->getSummary(new \ReflectionClass($error), '__construct');

        return OASResponse::create()
            ->statusCode($response->getStatusCode())
            ->description($summary)
            ->content(
                OASMediaType::json()->schema($this->extractFromArray($content))
            );
    }
}