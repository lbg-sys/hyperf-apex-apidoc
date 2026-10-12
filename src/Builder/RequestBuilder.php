<?php

declare(strict_types=1);

namespace Hyperf\Apex\ApiDoc\Builder;

use Hyperf\Apex\ApiDoc\Attribute\ApiHeader;
use Hyperf\Apex\ApiDoc\Attribute\FileField;
use Hyperf\Apex\ApiDoc\Compiler\SchemaFragmentTranslator;
use Hyperf\Apex\ApiDoc\DTOSchemaFactory;
use Hyperf\Apex\DTO\Schema\SchemaFragment;
use Hyperf\Apex\Http\ArgumentResolver\Attribute\FromBody;
use Hyperf\Apex\Http\ArgumentResolver\Attribute\FromMultipart;
use Hyperf\Apex\Http\ArgumentResolver\Attribute\FromQuery;
use Hyperf\Apex\Http\Middleware\IntegrationTokenMiddleware;
use Hyperf\Apex\Idempotency\Attribute\Idempotent;
use OpenApi\Spec as OA;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;
use ReflectionType;
use RuntimeException;

/**
 * 由控制器方法参数推导请求描述。
 *
 * #[FromQuery] 的 DTO 拍平成查询参数，#[FromBody] 的 DTO 作为 JSON 请求体。
 */
final class RequestBuilder
{
    public function __construct(
        private readonly DTOSchemaFactory $schemas,
        private readonly SchemaFragmentTranslator $translator,
    ) {
    }

    /**
     * 类级与方法级 #[ApiHeader] 合并，方法级同名覆盖类级。
     *
     * @param ReflectionClass<object> $controller
     * @return list<OA\Parameter>
     */
    public function headerParameters(ReflectionClass $controller, ReflectionMethod $method): array
    {
        $headers = [];
        foreach (array_merge($controller->getAttributes(ApiHeader::class), $method->getAttributes(ApiHeader::class)) as $attribute) {
            $header = $attribute->newInstance();
            self::assertNotAuthenticationHeader($header->name);
            $headers[$header->name] = new OA\Parameter(
                name: $header->name,
                in: 'header',
                description: $header->description !== '' ? $header->description : null,
                required: $header->required,
                schema: new OA\Schema(type: 'string'),
            );
        }

        $idempotent = $method->getAttributes(Idempotent::class)[0] ?? null;
        if ($idempotent !== null && $idempotent->newInstance()->requestIdField === null) {
            $headers[Idempotent::HEADER] = new OA\Parameter(
                name: Idempotent::HEADER,
                in: 'header',
                description: '幂等请求 ID；同一调用方对同一接口重试时必须保持不变。',
                required: true,
                schema: new OA\Schema(type: 'string', minLength: 8, maxLength: 128),
            );
        }

        return array_values($headers);
    }

    /**
     * 单次遍历参数得出查询参数与请求体，遍历顺序决定组件注册顺序，因此不拆成两趟。
     *
     * @return array{parameters: list<OA\Parameter>, requestBody: null|OA\RequestBody}
     */
    public function build(ReflectionMethod $method): array
    {
        $parameters = [];
        $bodyDto = null;
        $bodyProperties = [];
        $bodyRequired = [];

        foreach ($method->getParameters() as $parameter) {
            $body = $parameter->getAttributes(FromBody::class)[0] ?? null;
            $query = $parameter->getAttributes(FromQuery::class)[0] ?? null;
            if ($body === null && $query === null) {
                continue;
            }

            $type = $parameter->getType();
            $dtoClass = $this->dtoClassOf($type);
            if ($dtoClass !== null) {
                if ($query !== null) {
                    $this->appendParameters($parameters, $this->flattenDto($dtoClass), $method);
                    continue;
                }

                if ($bodyDto !== null) {
                    throw new RuntimeException(sprintf('%s 有多个 #[FromBody] DTO 参数。', self::describe($method)));
                }

                $bodyDto = $this->schemas->refFor($dtoClass);
                continue;
            }

            $name = $this->parameterName($parameter, ($body ?? $query)->newInstance());
            // 默认值与可空标记都跟 DTO 字段同一条判据：有缺省值就写出来，
            // `?string` 这类允许 null 的参数也要标出来 —— 文档不能比服务端更严。
            $fragment = DTOSchemaFactory::withInferredDefault($this->scalarFragment($type, $method), $parameter);
            if ($type !== null && $type->allowsNull()) {
                $fragment = $fragment->with('nullable', true);
            }
            $required = $this->isRequired($parameter);

            if ($query !== null) {
                $this->appendParameters($parameters, [new OA\Parameter(
                    name: $name,
                    in: 'query',
                    required: $required,
                    schema: $this->translator->translate($this->annotateQueryFragment($fragment, $name)),
                )], $method);
                continue;
            }

            $bodyProperties[$name] = $fragment;
            if ($required) {
                $bodyRequired[] = $name;
            }
        }

        $multipart = $this->multipartBody($method);
        if ($multipart !== null) {
            // multipart 与 JSON body 不能共存；query 参数则可以，之前是被整体丢掉的。
            if ($bodyDto !== null || $bodyProperties !== []) {
                throw new RuntimeException(sprintf('%s 同时声明了 multipart 表单与 JSON 请求体。', self::describe($method)));
            }

            return ['parameters' => $parameters, 'requestBody' => $multipart];
        }

        if ($bodyDto !== null && $bodyProperties !== []) {
            throw new RuntimeException(sprintf(
                '%s 同时用 #[FromBody] 声明了 DTO 和散装标量参数，标量部分不会进文档。',
                self::describe($method)
            ));
        }

        return ['parameters' => $parameters, 'requestBody' => $this->body($bodyDto, $bodyProperties, $bodyRequired)];
    }

    /**
     * 认证请求头不许再用 #[ApiHeader] 声明。
     *
     * 它们已经由访问声明推导成 securityScheme（见 SecurityResolver），再声明一遍会让同一份请求头
     * 同时出现在 securityScheme 与 parameters 里，文档自相矛盾：调用方不知道该按哪一份填。
     */
    private static function assertNotAuthenticationHeader(string $name): void
    {
        $reserved = [
            SecurityResolver::BEARER_HEADER,
            SecurityResolver::API_KEY_HEADER,
            IntegrationTokenMiddleware::HEADER,
        ];

        if (in_array(strtolower($name), array_map(strtolower(...), $reserved), true)) {
            throw new RuntimeException(sprintf(
                '请求头 %s 是认证头：它由访问声明推导成 securityScheme，不要再用 #[ApiHeader] 声明。',
                $name,
            ));
        }
    }

    /**
     * 追加查询参数并拒绝重名 —— 规格要求 name + in 组合唯一，
     * 而两个 #[FromQuery] DTO 有同名属性时原先会静默产出两条同名参数。
     *
     * @param list<OA\Parameter> $parameters
     * @param list<OA\Parameter> $additions
     */
    private function appendParameters(array &$parameters, array $additions, ReflectionMethod $method): void
    {
        foreach ($additions as $addition) {
            foreach ($parameters as $existing) {
                if ($existing->name === $addition->name && $existing->in === $addition->in) {
                    throw new RuntimeException(sprintf(
                        '%s 有重复的查询参数 %s。',
                        self::describe($method),
                        (string) $addition->name
                    ));
                }
            }

            $parameters[] = $addition;
        }
    }

    private static function describe(ReflectionMethod $method): string
    {
        return $method->getDeclaringClass()->getName() . '::' . $method->getName() . '()';
    }

    /**
     * @param array<string, SchemaFragment> $properties
     * @param list<string> $required
     */
    private function body(?SchemaFragment $dto, array $properties, array $required): ?OA\RequestBody
    {
        if ($dto !== null) {
            return new OA\RequestBody(
                required: true,
                content: new OA\MediaType(mediaType: 'application/json', schema: $this->translator->translate($dto)),
            );
        }

        if ($properties === []) {
            return null;
        }

        $fragment = SchemaFragment::of(
            ['type' => 'object', 'properties' => $properties] + ($required !== [] ? ['required' => $required] : [])
        );

        return new OA\RequestBody(
            required: $required !== [],
            content: new OA\MediaType(mediaType: 'application/json', schema: $this->translator->translate($fragment)),
        );
    }

    /**
     * multipart 表单：#[FromMultipart] DTO 的字段与 #[FileField] 声明的文件字段合并成一个表单。
     *
     * 表单结构不复用为组件 —— 它是一次性的传输格式，且文件字段并不属于 DTO。
     */
    private function multipartBody(ReflectionMethod $method): ?OA\RequestBody
    {
        $files = array_map(
            static fn (ReflectionAttribute $attribute): FileField => $attribute->newInstance(),
            $method->getAttributes(FileField::class)
        );

        $formDto = null;
        foreach ($method->getParameters() as $parameter) {
            if ($parameter->getAttributes(FromMultipart::class) === []) {
                continue;
            }

            $formDto = $this->dtoClassOf($parameter->getType());
            if ($formDto !== null) {
                break;
            }
        }

        if ($formDto === null && $files === []) {
            return null;
        }

        $properties = [];
        $required = [];
        if ($formDto !== null) {
            $fragment = $this->schemas->objectFragment($formDto);
            /** @var array<string, SchemaFragment> $properties */
            $properties = (array) $fragment->get('properties', []);
            $required = (array) $fragment->get('required', []);
        }

        $encodings = [];
        foreach ($files as $file) {
            $properties[$file->name] = $this->fileFragment($file);
            if ($file->required) {
                $required[] = $file->name;
            }
            if ($file->mediaType !== null) {
                $encodings[] = new OA\Encoding(encoding: $file->name, contentType: $file->mediaType);
            }
        }

        $form = SchemaFragment::of(
            ['type' => 'object', 'properties' => $properties]
            + ($required !== [] ? ['required' => array_values(array_unique($required))] : [])
        );

        return new OA\RequestBody(
            required: true,
            content: new OA\MediaType(
                mediaType: 'multipart/form-data',
                schema: $this->translator->translate($form),
                encoding: $encodings !== [] ? $encodings : null,
            ),
        );
    }

    /**
     * 二进制字段同时给出 format: binary 与 contentMediaType：
     * 前者是 3.0 的表达方式，后者是 3.1 推荐的方式，各版本编译器各取所需。
     */
    private function fileFragment(FileField $file): SchemaFragment
    {
        $binary = SchemaFragment::ofPresent([
            'type' => 'string',
            'format' => 'binary',
            'contentMediaType' => $file->mediaType ?? 'application/octet-stream',
            'description' => $file->description !== '' ? $file->description : null,
        ]);

        if (! $file->multiple) {
            return $binary;
        }

        return SchemaFragment::ofPresent([
            'type' => 'array',
            'items' => $binary,
            'description' => $file->description !== '' ? $file->description : null,
        ]);
    }

    /**
     * query DTO 拍平成逐个查询参数。
     *
     * 刻意不注册成组件 —— 它的字段已经全部铺进 query 参数，注册只会在 components
     * 里留下无人引用的孤儿条目（同时用作请求体的 DTO 会由请求体那条路径注册）。
     *
     * @return list<OA\Parameter>
     */
    private function flattenDto(string $dtoClass): array
    {
        $definition = $this->schemas->objectFragment($dtoClass);
        /** @var array<string, SchemaFragment> $properties */
        $properties = (array) $definition->get('properties', []);
        $required = (array) $definition->get('required', []);

        $parameters = [];
        foreach ($properties as $name => $fragment) {
            $parameters[] = new OA\Parameter(
                name: (string) $name,
                in: 'query',
                required: in_array($name, $required, true),
                schema: $this->translator->translate($this->annotateQueryFragment($fragment, (string) $name)),
            );
        }

        return $parameters;
    }

    /**
     * 数组类型的查询参数补一句线格式说明。
     *
     * OpenAPI 的 style/explode 无法表达 PHP 的方括号数组语法：form + explode 描述的是
     * `codes=a&codes=b`，而本项目走 getQueryParams()，实际需要 `codes[]=a&codes[]=b`。
     * 与其声明一个不符实的 style，不如把真实格式写进说明里 —— 但必须带上真实参数名，
     * 写死占位符等于给了读者一个错的例子。
     */
    private function annotateQueryFragment(SchemaFragment $fragment, string $name): SchemaFragment
    {
        if ($fragment->get('type') !== 'array') {
            return $fragment;
        }

        return $fragment->merge(SchemaFragment::of([
            'description' => sprintf('数组参数使用方括号语法重复传递，例如 ?%1$s[]=a&%1$s[]=b', $name),
        ]));
    }

    private function scalarFragment(?ReflectionType $type, ReflectionMethod $method): SchemaFragment
    {
        if (! $type instanceof ReflectionNamedType) {
            // 联合与交集类型没有单一的标量表达，落到 type: string 就是撒谎。
            throw new RuntimeException(sprintf('%s 的散装请求参数必须是单一的标量类型。', self::describe($method)));
        }

        if (in_array($type->getName(), ['array', 'iterable'], true)) {
            /*
             * 散装数组参数没有地方声明元素类型（#[ArrayOf] 只能标在 DTO 属性或方法上），
             * 于是只能产出没有 items 的 `type: array` —— 上游把这种形态判为 warning，
             * 整个生成命令都会失败，而报出来的是一条与业务代码无关的编译器提示。
             * 想表达数组参数就用一个查询 DTO 承载它。
             */
            throw new RuntimeException(sprintf(
                '%s 的数组参数必须有元素类型：请改用查询 DTO 承载，并在其属性上标 #[ArrayOf(ElementDTO::class)]。',
                self::describe($method),
            ));
        }

        return DTOSchemaFactory::primitiveFragment($type->getName());
    }

    /**
     * 参数类型指向的 DTO 类名；不是单一的非内置类型时返回 null。
     *
     * 返回类名而不是 bool：调用方紧接着就要取类名，而 PHPStan 无法把「isDtoType() 返回 true」
     * 与「$type instanceof ReflectionNamedType」关联起来，会持续报「基类 ReflectionType
     * 没有 getName()」的假阳性（getType() 的静态类型是基类）。
     */
    private function dtoClassOf(?ReflectionType $type): ?string
    {
        if (! $type instanceof ReflectionNamedType || $type->isBuiltin()) {
            return null;
        }

        $name = $type->getName();

        return class_exists($name) ? $name : null;
    }

    private function isRequired(ReflectionParameter $parameter): bool
    {
        return ! $parameter->isDefaultValueAvailable() && ! $parameter->allowsNull();
    }

    private function parameterName(ReflectionParameter $parameter, object $attribute): string
    {
        return $attribute->name ?? $parameter->getName();
    }
}
