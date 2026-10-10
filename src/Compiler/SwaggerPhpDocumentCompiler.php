<?php

declare(strict_types=1);

namespace Hyperf\Apex\ApiDoc\Compiler;

use OpenApi\Compiler\OpenApi30Compiler;
use OpenApi\Compiler\OpenApi31Compiler;
use OpenApi\Compiler\OpenApi32Compiler;
use OpenApi\Contracts\CompilerInterface;
use OpenApi\Specification;
use RuntimeException;

/**
 * 基于 swagger-php 的编译实现。
 *
 * 按目标版本挑选对应 compiler：版本差异（3.0 的 nullable / boolean exclusiveMinimum
 * 与 3.1 的类型数组 / const / prefixItems / if-then-else 等）全部由它承担，
 * 项目侧只需描述语义。
 */
final class SwaggerPhpDocumentCompiler implements DocumentCompilerInterface
{
    private readonly CompilerInterface $compiler;

    public function __construct(private readonly string $version)
    {
        $this->compiler = $this->resolve($version);
    }

    public function version(): string
    {
        return $this->version;
    }

    public function compile(Specification $specification): array
    {
        $specification->openapi->version = $this->version;

        return $this->compiler->compile($specification);
    }

    public function validate(Specification $specification): array
    {
        return $this->compiler->validate($specification);
    }

    private function resolve(string $version): CompilerInterface
    {
        foreach ([new OpenApi31Compiler(), new OpenApi30Compiler(), new OpenApi32Compiler()] as $candidate) {
            if ($candidate->supports($version)) {
                return $candidate;
            }
        }

        throw new RuntimeException(sprintf('不支持的 OpenAPI 版本：%s。', $version));
    }
}
