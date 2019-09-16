<?php

if (! function_exists('generate_random_string')) {
    function method_type(ReflectionMethod $method): ?string
    {
        foreach ($method->getParameters() as $parameter) {
            if ($parameter->hasType()) {
                return $parameter->getType()->getName();
            }
        }

        return null;
    }
}