<?php

declare(strict_types=1);

namespace Hyperf\Apex\ApiDoc\Attribute;

use Attribute;

#[Attribute(Attribute::TARGET_METHOD)]
final class ApiOperation
{
    /** @param list<string> $tags */
    public function __construct(public readonly string $summary, public readonly string $description = '', public readonly array $tags = [])
    {
    }
}
