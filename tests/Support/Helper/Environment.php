<?php

declare(strict_types=1);

namespace App\Tests\Support\Helper;

use Codeception\Module;
use Codeception\Module\Symfony\EnvironmentAssertionsTrait;

class Environment extends Module
{
    use EnvironmentAssertionsTrait;
}
