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
use Hyperf\Apex\DTO\Schema\SchemaFragment;
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
    /** 组件引用的前缀，与 DTOSchemaFactory / SchemaReference 生成的形态一致。 */
    private const REF_PREFIX = '#/components/schemas/';

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
        $this->assertNoDanglingReferences();
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
     * 组件之间不许出现悬空引用。
     *
     * 典型来源是 `#[Discriminator]` 的 mapping：它按短类名直接拼出引用、不会注册组件，
     * mapping 里的类必须同时出现在 `#[OneOf]` 里才会被注册。漏写时产物里就留下一个指向不存在
     * 组件的 `$ref`，而编译器自带的 validate() 不查这一项 —— 要等下游导入文档时才炸。
     *
     * 只查「组件 → 组件」的引用：组件是否被某个 operation 引用（孤儿组件）需要另外遍历 OA
     * 对象树，收益不抵复杂度，而错误响应与安全方案本来就是「只注册被引用到的」。
     */
    private function assertNoDanglingReferences(): void
    {
        $fragments = $this->schemas->componentFragments();

        $registered = [];
        foreach (array_keys($fragments) as $name) {
            $registered[(string) $name] = true;
        }

        foreach ($fragments as $name => $fragment) {
            foreach (self::collectRefs($fragment) as $ref) {
                if (! str_starts_with($ref, self::REF_PREFIX)) {
                    continue;
                }

                $target = substr($ref, strlen(self::REF_PREFIX));
                if (! isset($registered[$target])) {
                    throw new RuntimeException(sprintf(
                        '组件 %s 引用了未注册的组件 %s：请把该类一并写进对应的 #[OneOf]'
                        . '（#[Discriminator] 的 mapping 不会注册组件）。',
                        (string) $name,
                        $target,
                    ));
                }
            }
        }
    }

    /**
     * 递归收集 fragment 里出现的组件引用。
     *
     * 不只看 `ref` 键：`#[Discriminator]` 把引用写在 `discriminator.mapping` 的值里，
     * 按关键字白名单收集会漏掉它。
     *
     * @return list<string>
     */
    private static function collectRefs(mixed $value): array
    {
        if ($value instanceof SchemaFragment) {
            $refs = [];
            foreach ($value->keywords() as $item) {
                array_push($refs, ...self::collectRefs($item));
            }

            return $refs;
        }

        if (is_string($value)) {
            return str_contains($value, self::REF_PREFIX) ? [$value] : [];
        }

        if (is_array($value)) {
            $refs = [];
            foreach ($value as $item) {
                array_push($refs, ...self::collectRefs($item));
            }

            return $refs;
        }

        return [];
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
