<?php

namespace Bchalier\LaravelOpenapiDoc\App\Exceptions;

use Illuminate\Http\Resources\Json\JsonResource;

class JsonResourceNoType extends \Exception
{
    /**
     * @param JsonResource $resource [optional]
     * @param \Throwable   $previous [optional] The previous throwable used for the exception chaining.
     *
     * @return void
     */
    public function __construct(JsonResource $resource, \Throwable $previous = null)
    {
        $message = 'The constructor of ' . get_class($resource) . ' need to have a type in order to document it, example: __construct(MyAwesomeModel $resource)';

        parent::__construct($message, null, $previous);
    }
}
