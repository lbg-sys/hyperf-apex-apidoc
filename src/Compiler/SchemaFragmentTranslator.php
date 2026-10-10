<?php

declare(strict_types=1);

namespace Hyperf\Apex\ApiDoc\Compiler;

use Hyperf\Apex\DTO\Schema\SchemaFragment;
use OpenApi\Spec as OA;
use RuntimeException;

/**
 * 把项目内部的 SchemaFragment 翻译成 swagger-php 的 Spec\Schema。
 *
 * 这是全项目唯一知道 swagger-php Schema 结构的地方。上游若调整字段，
 * 改动面限于本类，不会波及任何 Attribute 或 DTO。
 */
final class SchemaFragmentTranslator
{
    /**
     * 3.0.x 里没有对应表达、会被 swagger-php 的 3.0 编译器**静默丢弃**的关键字。
     *
     * 这份清单是实测出来的（逐个关键字分别用 3.1.1 与 3.0.3 编译后比对产物），
     * 不含可以降级的那些：`const` → 单值 `enum`、类型数组 → `type + nullable`、
     * `examples` → `example`，它们的语义在 3.0 里仍然表达得出来。
     */
    private const UNSUPPORTED_IN_THREE_OH = [
        'prefixItems', 'contains', 'minContains', 'maxContains',
        'unevaluatedItems', 'unevaluatedProperties', 'patternProperties',
        'dependentRequired', 'dependentSchemas', 'propertyNames',
        'if', 'then', 'else', 'contentMediaType', 'contentEncoding',
    ];

    /** 值本身是单个 Schema 的关键字。 */
    private const SCHEMA_VALUED = [
        'items', 'not', 'if', 'then', 'else', 'propertyNames',
        'contains', 'unevaluatedItems', 'unevaluatedProperties', 'additionalProperties',
    ];

    /** 值是 Schema 列表的关键字。 */
    private const SCHEMA_LIST_VALUED = ['allOf', 'anyOf', 'oneOf', 'prefixItems'];

    /** 值是「名称 => Schema」映射的关键字。 */
    private const SCHEMA_MAP_VALUED = ['patternProperties', 'dependentSchemas'];

    /** 值是数组、但需要换成 Spec 对象的关键字。 */
    private const OBJECT_VALUED = [
        'discriminator' => OA\Discriminator::class,
        'externalDocs' => OA\ExternalDocumentation::class,
        'xml' => OA\Xml::class,
    ];

    /**
     * @param null|string $targetVersion 目标 OpenAPI 版本；非 3.0.x 或未指定时不做专有词汇检查
     */
    public function __construct(private readonly ?string $targetVersion = null)
    {
    }

    /**
     * @param null|string $componentName 非空时作为 components.schemas 的键
     */
    public function translate(SchemaFragment $fragment, ?string $componentName = null): OA\Schema
    {
        $this->assertRepresentable($fragment, $componentName ?? '请求/响应 Schema');

        $arguments = [];
        if ($componentName !== null) {
            /*
             * 用 `component:` 而不是 `schema:`：swagger-php 6.12 起后者已弃用（会触发 deprecation），
             * 并将在 8.0 移除。两者在当前版本等价 —— 构造函数会把 `schema` 回填成 `component` ——
             * 但继续写 `schema` 等于把移除日期留在了依赖升级里。
             */
            $arguments['component'] = $componentName;
        }

        foreach ($fragment->keywords() as $keyword => $value) {
            $arguments[$keyword] = $this->translateValue($keyword, $value);
        }

        // 具名实参展开：未出现的关键字保持 swagger-php 的默认值，
        // 从而正确区分「未设置」与「显式 null」（description/const/example/default 用哨兵）。
        return new OA\Schema(...$arguments);
    }

    /**
     * 目标版本 3.0.x 时，拒绝 3.1 专有词汇。
     *
     * 编译层对它们没有任何报错，只是不输出 —— 文档看起来完全正常，实际少了 DTO 上声明的约束，
     * 这正是这个项目一直在别处拒绝的「骗人的文档」。宁可让生成命令失败，也不要产出一份
     * 少了一条约束却看不出来的契约。
     *
     * 递归到嵌套片段：`patternProperties` 出现在 `items` 里同样会被丢掉。
     */
    private function assertRepresentable(SchemaFragment $fragment, string $where): void
    {
        if ($this->targetVersion === null || ! str_starts_with($this->targetVersion, '3.0')) {
            return;
        }

        foreach ($fragment->keywords() as $keyword => $value) {
            if (in_array($keyword, self::UNSUPPORTED_IN_THREE_OH, true)) {
                throw new RuntimeException(sprintf(
                    '%s 用了 OpenAPI 3.1 专有关键字 %s，目标版本 %s 表达不出来（编译时会被丢弃）。'
                    . '请把 api_doc.version 提到 3.1.x，或去掉声明它的那个 Attribute。',
                    $where,
                    $keyword,
                    $this->targetVersion,
                ));
            }

            if ($value instanceof SchemaFragment) {
                $this->assertRepresentable($value, $where);
                continue;
            }

            if (is_array($value)) {
                foreach ($value as $item) {
                    if ($item instanceof SchemaFragment) {
                        $this->assertRepresentable($item, $where);
                    }
                }
            }
        }
    }

    private function translateValue(string $keyword, mixed $value): mixed
    {
        if (in_array($keyword, self::SCHEMA_VALUED, true)) {
            return $value instanceof SchemaFragment ? $this->translate($value) : $value;
        }

        if (in_array($keyword, self::SCHEMA_LIST_VALUED, true)) {
            return array_values(array_map($this->translateNested(...), (array) $value));
        }

        if (in_array($keyword, self::SCHEMA_MAP_VALUED, true)) {
            return array_map($this->translateNested(...), (array) $value);
        }

        if ($keyword === 'properties') {
            return $this->translateProperties((array) $value);
        }

        // discriminator / externalDocs / xml 在 Spec 里是独立对象，片段用具名数组承载。
        if (isset(self::OBJECT_VALUED[$keyword]) && is_array($value)) {
            $class = self::OBJECT_VALUED[$keyword];

            return new $class(...$value);
        }

        return $value;
    }

    private function translateNested(mixed $value): mixed
    {
        return $value instanceof SchemaFragment ? $this->translate($value) : $value;
    }

    /**
     * SchemaFragment 用「属性名 => 片段」表达对象属性，
     * swagger-php 用 list<Property>，需要在这里换形。
     *
     * properties 的取值来自 SchemaFragment 的载荷（可能已被翻译过，也可能是原样标量），
     * 所以这里只能声明成 array<array-key, mixed>，由分支内的 instanceof 决定怎么处理。
     *
     * @param array<array-key, mixed> $properties
     *
     * @return list<OA\Property>
     */
    private function translateProperties(array $properties): array
    {
        $translated = [];
        foreach ($properties as $name => $fragment) {
            $translated[] = new OA\Property(
                property: (string) $name,
                schema: $fragment instanceof SchemaFragment ? $this->translate($fragment) : $fragment,
            );
        }

        return $translated;
    }
}
