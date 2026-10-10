<?php

declare(strict_types=1);

namespace Hyperf\Apex\ApiDoc\Attribute;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
final class ApiHeader
{
    public function __construct(
        public readonly string $name,
        public readonly string $description = '',
        public readonly bool $required = false
    ) {
    }
}
