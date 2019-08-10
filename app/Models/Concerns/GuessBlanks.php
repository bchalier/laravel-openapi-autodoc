<?php

namespace Bchalier\LaravelOpenapiDoc\App\Models\Concerns;

trait GuessBlanks
{
    protected function guessExample()
    {
        $this->example = 'dunno, yet !'; // TODO
    }

    protected function generateRandomString($regex)
    {

    }
}