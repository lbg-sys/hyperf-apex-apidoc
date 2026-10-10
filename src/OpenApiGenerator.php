<?php

declare(strict_types=1);

namespace Hyperf\Apex\ApiDoc;

use Hyperf\Apex\ApiDoc\Builder\ControllerScanner;
use Hyperf\Apex\ApiDoc\Builder\ErrorResponseCatalog;
use Hyperf\Apex\ApiDoc\Builder\OperationBuilder;
use Hyperf\Apex\ApiDoc\Builder\RequestBuilder;
use Hyperf\Apex\ApiDoc\Builder\ResponseBuilder;
use Hyperf\Apex\ApiDoc\Builder\ReturnTypeResolver;
use Hyperf\Apex\ApiDoc\Builder\SecurityResolver;
use Hyperf\Apex\ApiDoc\Compiler\DocumentCompilerInterface;
use Hyperf\Apex\ApiDoc\Compiler\SchemaFragmentTranslator;
use Hyperf\Apex\Http\Response\Contract\ApiResponseInterface;
use Hyperf\Apex\Http\Response\ResponseEncoderResolver;
use Hyperf\Apex\RateLimit\RateLimitRuleReader;
use Hyperf\Apex\Security\AccessDeclarationReader;
use OpenApi\Spec as OA;
use OpenApi\Specification;
use RuntimeException;

/**
 * 扫描控制器与 DTO，装配 OpenAPI 规格并交由编译层落成文档。
 */
final class OpenApiGenerator
{
    private readonly DTOSchemaFactory $schemas;

    private readonly ControllerScanner $scanner;

    private readonly OperationBuilder $operations;

    private readonly SchemaFragmentTranslator $translator;

    private readonly ErrorResponseCatalog $errors;

    private readonly SecurityResolver $security;

    /**
     * @param class-string<ApiResponseInterface> $responseDtoClass
     * @param list<string> $excludedPatterns
     * @param array<string, mixed> $info
     * @param list<array<string, mixed>> $servers
     */
    public function __construct(
        string $responseDtoClass,
        ResponseEncoderResolver $encoders,
        private readonly DocumentCompilerInterface $compiler,
        array $excludedPatterns = [],
        private readonly array $info = [],
        private readonly array $servers = [],
    ) {
        if (! is_subclass_of($responseDtoClass, ApiResponseInterface::class)) {
            throw new RuntimeException(sprintf('Response DTO must implement %s.', ApiResponseInterface::class));
        }

        $this->schemas = new DTOSchemaFactory();
        // 把目标版本交给翻译层：目标是 3.0.x 时它要拒绝 3.1 专有词汇（否则会被编译层静默丢弃）。
        $this->translator = new SchemaFragmentTranslator($compiler->version());
        $this->scanner = new ControllerScanner($excludedPatterns);
        $this->errors = new ErrorResponseCatalog($this->translator);
        $this->security = new SecurityResolver(new AccessDeclarationReader());
        $requests = new RequestBuilder($this->schemas, $this->translator);
        $responses = new ResponseBuilder(
            $this->schemas,
            $this->translator,
            new ReturnTypeResolver($this->schemas),
            $encoders,
            $this->errors,
            $responseDtoClass,
        );
        $this->operations = new OperationBuilder($requests, $responses, $this->scanner, $this->security, new RateLimitRuleReader());
    }

    /**
     * @return array<string, mixed>
     */
    public function generate(string $appPath): array
    {
        return $this->compile($this->specification($appPath));
    }

    /**
     * @return array<string, mixed>
     */
    public function compile(Specification $specification): array
    {
        return $this->compiler->compile($specification);
    }

    /**
     * @return list<array{level: string, message: string}>
     */
    public function validate(Specification $specification): array
    {
        return $this->compiler->validate($specification);
    }

    /**
     * 编译前的规格，供校验与测试使用。
     */
    public function specification(string $appPath): Specification
    {
        $specification = new Specification();
        $specification->add($this->buildInfo(), ...$this->buildServers());

        $operations = [];
        foreach ($this->scanner->controllers($appPath) as $controller) {
            array_push($operations, ...$this->operations->build($controller));
        }

        usort($operations, static fn (OA\Operation $a, OA\Operation $b): int => [$a->path, $a->method] <=> [$b->path, $b->method]);
        self::assertUniqueOperationIds($operations);
        $specification->add(...$operations);

        foreach ($this->schemas->componentFragments() as $name => $fragment) {
            $specification->add($this->translator->translate($fragment, $name));
        }

        // 只注册真正被引用到的错误响应与安全方案，避免留下无人引用的组件。
        $specification->add(...$this->errors->components());
        $specification->add(...$this->security->components());

        return $specification;
    }

    /**
     * operationId 由 URL path + HTTP 方法派生（见 OperationBuilder::operationIdFromUrl()），
     * OpenAPI 的 (path, method) 唯一性保证它本不该重复；重复只可能来自两条不同的 URL
     * 被清洗成同一字符串（如 /a/b 与 /a_b）。编译器自带的 validate() 发现不了，仍在此兜底。
     *
     * @param list<OA\Operation> $operations
     */
    private static function assertUniqueOperationIds(array $operations): void
    {
        $seen = [];
        foreach ($operations as $operation) {
            $id = (string) $operation->operationId;
            if (isset($seen[$id])) {
                throw new RuntimeException(sprintf(
                    'operationId %s 重复（%s 与 %s）：两条不同 URL 被清洗成了同一个字符串，'
                    . '请调整其中一段路径分隔，避免 /a_b 与 /a/b 这类归一化冲突。',
                    $id,
                    $seen[$id],
                    $operation->method . ' ' . $operation->path
                ));
            }

            $seen[$id] = $operation->method . ' ' . $operation->path;
        }
    }

    /**
     * @return list<OA\Server>
     */
    private function buildServers(): array
    {
        $servers = [];
        foreach ($this->servers as $index => $server) {
            if (! isset($server['url']) || (string) $server['url'] === '') {
                // 静默跳过会让一个打错键名的配置项无声消失。
                throw new RuntimeException(sprintf('api_doc.servers[%s] 缺少 url。', (string) $index));
            }

            $servers[] = new OA\Server(
                url: (string) $server['url'],
                description: isset($server['description']) ? (string) $server['description'] : null,
            );
        }

        return $servers;
    }

    private function buildInfo(): OA\Info
    {
        foreach (['title', 'version'] as $required) {
            if (! isset($this->info[$required]) || (string) $this->info[$required] === '') {
                throw new RuntimeException(sprintf('api_doc.info.%s 必须配置。', $required));
            }
        }

        return new OA\Info(
            title: (string) $this->info['title'],
            description: isset($this->info['description']) ? (string) $this->info['description'] : null,
            version: (string) $this->info['version'],
            summary: isset($this->info['summary']) ? (string) $this->info['summary'] : null,
        );
    }
}
