<?php

namespace Bchalier\LaravelOpenapiDoc\App\Models\Concerns;

trait GuessBlanks
{
    protected function guessExample()
    {
        $this->example = null; // TODO
    }

    protected function generateRandomString($regex)
    {

    }
}