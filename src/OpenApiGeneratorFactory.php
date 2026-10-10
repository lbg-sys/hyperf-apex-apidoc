<?php

declare(strict_types=1);

namespace Hyperf\Apex\ApiDoc;

use Hyperf\Apex\ApiDoc\Compiler\SwaggerPhpDocumentCompiler;
use Hyperf\Apex\Http\Response\ApiResponseFactory;
use Hyperf\Apex\Http\Response\ResponseEncoderResolver;
use Hyperf\Contract\ConfigInterface;
use RuntimeException;

/**
 * 按 config/autoload/api_doc.php 组装生成器，供生成命令与测试共用同一套配线。
 */
final class OpenApiGeneratorFactory
{
    public function __construct(
        private readonly ApiResponseFactory $responses,
        private readonly ResponseEncoderResolver $encoders,
        private readonly ConfigInterface $config,
    ) {
    }

    public function make(): OpenApiGenerator
    {
        return new OpenApiGenerator(
            $this->responses->responseDtoClass(),
            $this->encoders,
            new SwaggerPhpDocumentCompiler((string) $this->config->get('api_doc.version', '3.1.1')),
            $this->excludedPatterns(),
            (array) $this->config->get('api_doc.info', []),
            array_values((array) $this->config->get('api_doc.servers', [])),
        );
    }

    /**
     * @return list<string>
     */
    private function excludedPatterns(): array
    {
        $excluded = $this->config->get('api_doc.excluded', []);
        if (! is_array($excluded) || array_filter($excluded, static fn (mixed $pattern): bool => ! is_string($pattern)) !== []) {
            throw new RuntimeException('配置 api_doc.excluded 必须是字符串数组。');
        }

        return array_values($excluded);
    }
}
