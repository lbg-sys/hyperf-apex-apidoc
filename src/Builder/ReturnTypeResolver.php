<?php

declare(strict_types=1);

namespace Hyperf\Apex\ApiDoc\Builder;

use Hyperf\Apex\ApiDoc\DTOSchemaFactory;
use Hyperf\Apex\DTO\Contract\ArrayOfAttribute;
use Hyperf\Apex\DTO\Metadata\TypeExpression;
use Hyperf\Apex\DTO\Schema\SchemaFragment;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionUnionType;
use RuntimeException;

/**
 * 由控制器方法的返回类型推导响应 data 的 Schema。
 *
 * 返回类型就是接口 data 的结构：具名 DTO 出 $ref，array 依赖方法级 #[ArrayOf]
 * 或 @return 注释确定元素类型，void 表示无业务数据。
 */
final class ReturnTypeResolver
{
    public function __construct(private readonly DTOSchemaFactory $schemas)
    {
    }

    public function fragmentFor(ReflectionMethod $method): SchemaFragment
    {
        $type = $method->getReturnType();

        if ($type instanceof ReflectionNamedType) {
            if ($type->getName() === 'void') {
                // 无业务数据：data 恒为 null，用 type: null 精确表达，而不是留一个空 schema。
                return SchemaFragment::of(['type' => 'null']);
            }

            $fragment = $type->getName() === 'array'
                ? $this->fragmentFromArrayReturn($method)
                : $this->fragmentForNamedReturn($type->getName(), $method);

            return $type->allowsNull() ? $fragment->with('nullable', true) : $fragment;
        }

        if (! $type instanceof ReflectionUnionType) {
            // 没有返回类型（或交集类型）时只能产出一个无约束的 data: {}，
            // 那等于什么都没描述。这是标注遗漏，停在构建期。
            throw new RuntimeException(sprintf(
                '%s 必须声明可表达的返回类型（响应 DTO、PaginationResultDTO 或 void）。',
                self::describe($method)
            ));
        }

        $fragments = [];
        $nullable = false;
        foreach ($type->getTypes() as $unionType) {
            if (! $unionType instanceof ReflectionNamedType) {
                continue;
            }
            if ($unionType->getName() === 'null') {
                $nullable = true;
                continue;
            }
            $fragments[] = $unionType->getName() === 'array'
                ? $this->fragmentFromArrayReturn($method)
                : $this->fragmentForNamedReturn($unionType->getName(), $method);
        }

        $fragment = match (count($fragments)) {
            0 => SchemaFragment::empty(),
            1 => $fragments[0],
            default => SchemaFragment::of(['oneOf' => $fragments]),
        };

        return $nullable ? $fragment->with('nullable', true) : $fragment;
    }

    public function fragmentForType(string $type): SchemaFragment
    {
        return $this->schemas->fragmentForTypeName($type);
    }

    private function fragmentFromArrayReturn(ReflectionMethod $method): SchemaFragment
    {
        foreach ($method->getAttributes() as $attribute) {
            $instance = $attribute->newInstance();
            if ($instance instanceof ArrayOfAttribute && $instance->targetProperty() === null) {
                return SchemaFragment::of(['type' => 'array', 'items' => $this->fragmentForType($instance->itemType())]);
            }
        }

        // 裸 array 且没有元素类型声明，只能产出没有 items 的数组 —— 那是骗人的文档。
        return $this->fragmentFromDocblock($method) ?? throw new RuntimeException(sprintf(
            '%s 返回 array，必须用方法级 #[ArrayOf(ItemDTO::class)] 或 @return 注释声明元素类型。',
            self::describe($method)
        ));
    }

    private static function describe(ReflectionMethod $method): string
    {
        return $method->getDeclaringClass()->getName() . '::' . $method->getName() . '()';
    }

    /**
     * 泛型 DTO（如 PaginationResultDTO<ItemDTO>）通过方法级 #[ArrayOf(..., property: 'list')]
     * 指定列表元素类型，因此需要内联展开后改写对应属性的 items。
     */
    private function fragmentForNamedReturn(string $type, ReflectionMethod $method): SchemaFragment
    {
        $propertyItemTypes = [];
        foreach ($method->getAttributes() as $attribute) {
            $instance = $attribute->newInstance();
            if (! $instance instanceof ArrayOfAttribute || $instance->targetProperty() === null) {
                continue;
            }

            $property = $instance->targetProperty();
            if (isset($propertyItemTypes[$property])) {
                throw new RuntimeException(sprintf('Response property %s::%s has multiple array item types.', $type, $property));
            }
            $propertyItemTypes[$property] = $instance->itemType();
        }

        if ($propertyItemTypes === []) {
            // 走工厂的统一叶子解析：枚举展开成 {key, name}，接口与抽象类直接报错，
            // 而不是当成普通类反射出一个空壳组件。
            return $this->fragmentForType($type);
        }

        if (! class_exists($type)) {
            throw new RuntimeException(sprintf('%s 的返回类型 %s 不是可反射的类。', self::describe($method), $type));
        }

        $fragment = $this->schemas->objectFragment($type);
        /** @var array<string, SchemaFragment> $properties */
        $properties = (array) $fragment->get('properties', []);
        foreach ($propertyItemTypes as $property => $itemType) {
            if (! isset($properties[$property])) {
                throw new RuntimeException(sprintf('Response property %s::%s does not exist.', $type, $property));
            }
            if ($properties[$property]->get('type') !== 'array') {
                throw new RuntimeException(sprintf('Response property %s::%s must be an array.', $type, $property));
            }

            $properties[$property] = $properties[$property]->with('items', $this->fragmentForType($itemType));
        }

        return $this->schemas->refForSpecialization(
            self::specializationName($type, $propertyItemTypes),
            $fragment->with('properties', $properties),
        );
    }

    /**
     * 泛型实例化的组件名：基础 DTO 名 + 「属性名 + 元素类型名」，例如 PaginationResultDTO_list_ConfigDTO。
     *
     * 属性名必须进名字：同一个基础 DTO 的两个不同属性配同样的元素类型会撞名，
     * 而撞名时静默保留第一份结构会让另一个接口拿到它从不返回的形状。
     *
     * @param array<string, string> $propertyItemTypes
     */
    private static function specializationName(string $type, array $propertyItemTypes): string
    {
        $parts = [DTOSchemaFactory::componentName($type)];
        foreach ($propertyItemTypes as $property => $itemType) {
            $parts[] = $property;
            $parts[] = class_exists($itemType) ? DTOSchemaFactory::componentName($itemType) : ucfirst($itemType);
        }

        return implode('_', $parts);
    }

    /**
     * 从注释里取出 @return 的类型表达式，剥掉紧跟在后面的说明文字。
     *
     * 直接按整行取会把说明当成类型的一部分：`list<DemoDTO> 列表` 只是靠泛型解析的贪婪匹配侥幸
     * 能用，`FooDTO[] 列表` 会退化成「类名是 FooDTO[] 列表」的叶子，`array<string, FooDTO> 映射`
     * 因为结尾不是 `>` 直接解析失败 —— 回落成没有 items 的裸 array，而那是上游判 warning、
     * 足以让整个生成命令失败的形态。
     *
     * 这里按 `<>`（连同 `{}` 形状）配对深度扫描：深度归零后遇到空白即认为类型结束。
     */
    private static function docblockType(string $docComment): ?string
    {
        if (! preg_match('/@return\s+([^\r\n*]+)/', $docComment, $matches)) {
            return null;
        }

        $rest = trim($matches[1]);
        $type = '';
        $depth = 0;
        for ($index = 0, $length = strlen($rest); $index < $length; ++$index) {
            $character = $rest[$index];
            if ($character === '<' || $character === '{') {
                ++$depth;
            } elseif ($character === '>' || $character === '}') {
                $depth = max(0, $depth - 1);
            } elseif ($depth === 0 && ($character === ' ' || $character === "\t")) {
                break;
            }

            $type .= $character;
        }

        $type = trim($type);

        return $type === '' ? null : $type;
    }

    private function fragmentFromDocblock(ReflectionMethod $method): ?SchemaFragment
    {
        $docComment = $method->getDocComment();
        if ($docComment === false) {
            return null;
        }

        $declared = self::docblockType($docComment);
        if ($declared === null) {
            return null;
        }

        $nullable = str_starts_with($declared, '?');
        $fragment = null;
        foreach (explode('|', ltrim($declared, '?')) as $candidate) {
            $candidate = trim($candidate);
            if ($candidate === 'null') {
                $nullable = true;
                continue;
            }
            $fragment ??= $this->fragmentFromDocblockType($candidate, $method->getDeclaringClass());
        }

        if ($fragment === null) {
            return null;
        }

        return $nullable ? $fragment->with('nullable', true) : $fragment;
    }

    /**
     * @param ReflectionClass<object> $declaringClass
     */
    private function fragmentFromDocblockType(string $type, ReflectionClass $declaringClass): SchemaFragment
    {
        $expression = TypeExpression::tryParse($type);
        if ($expression === null) {
            // array{...} 形状、泛型参数内的联合等无法归约成元素类型；
            // 但能走到这里说明 PHP 返回类型已经是 array，这一层信息是可靠的。
            return DTOSchemaFactory::primitiveFragment('array');
        }

        return $this->schemas->fragmentForExpression(
            $expression->mapLeaves(fn (string $leaf): string => $this->resolveDocblockType($leaf, $declaringClass))
        );
    }

    /**
     * @param ReflectionClass<object> $declaringClass
     */
    private function resolveDocblockType(string $type, ReflectionClass $declaringClass): string
    {
        $type = ltrim(trim($type), '\\');
        if (class_exists($type) || interface_exists($type)) {
            return $type;
        }

        $aliases = $this->docblockClassAliases($declaringClass);
        if (isset($aliases[$type])) {
            return $aliases[$type];
        }

        $namespaced = $declaringClass->getNamespaceName() . '\\' . $type;

        return class_exists($namespaced) || interface_exists($namespaced) ? $namespaced : $type;
    }

    /**
     * 解析文件顶部的 use 语句，用于把注释里的短类名还原成完整类名。
     *
     * @param ReflectionClass<object> $declaringClass
     * @return array<string, string>
     */
    private function docblockClassAliases(ReflectionClass $declaringClass): array
    {
        $fileName = $declaringClass->getFileName();
        if ($fileName === false) {
            return [];
        }

        $contents = file_get_contents($fileName);
        if ($contents === false || ! preg_match_all('/^use\s+([^;]+);/mi', $contents, $matches)) {
            return [];
        }

        $aliases = [];
        foreach ($matches[1] as $import) {
            $import = trim($import);
            if (str_contains($import, '{')) {
                continue;
            }

            $parts = preg_split('/\s+as\s+/i', $import);
            $class = ltrim(trim($parts[0]), '\\');
            $alias = trim($parts[1] ?? basename(str_replace('\\', '/', $class)));
            $aliases[$alias] = $class;
        }

        return $aliases;
    }
}
