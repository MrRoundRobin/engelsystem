<?php

declare(strict_types=1);

namespace Engelsystem\Test\Unit\Controllers\Stub;

use Engelsystem\Controllers\BaseController;

class ControllerImplementation extends BaseController
{
    /** @var array */
    protected array $permissions = [
        'foo',
        'lorem' => [
            'ipsum',
            'dolor',
        ],
    ];
}
