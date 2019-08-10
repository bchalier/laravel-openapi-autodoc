<?php

namespace Bchalier\LaravelOpenapiDoc\App\Exceptions;

class ResponseTypeNotSupported extends \Exception
{
    /**
     * @param            $resource [optional]
     * @param \Throwable $previous [optional] The previous throwable used for the exception chaining.
     *
     * @return void
     */
    public function __construct($resource, \Throwable $previous = null)
    {
        $message = 'The resource ' . $resource . ' is not yet supported and can\'t be documented.';

        parent::__construct($message, null, $previous);
    }
}
