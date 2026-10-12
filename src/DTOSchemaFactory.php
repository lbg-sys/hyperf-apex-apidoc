<?php

declare(strict_types=1);

namespace Hyperf\Apex\ApiDoc;

use Hyperf\Apex\DTO\Contract\ArrayOfAttribute;
use Hyperf\Apex\DTO\Contract\ItemSchemaAttribute;
use Hyperf\Apex\DTO\Contract\NonNullableAttribute;
use Hyperf\Apex\DTO\Contract\OptionalAttribute;
use Hyperf\Apex\DTO\Contract\RequiredAttribute;
use Hyperf\Apex\DTO\Contract\SchemaAttribute;
use Hyperf\Apex\DTO\Contract\TypedSchemaAttribute;
use Hyperf\Apex\DTO\Metadata\TypeExpression;
use Hyperf\Apex\DTO\Schema\ComponentName;
use Hyperf\Apex\DTO\Schema\SchemaFragment;
use Hyperf\Apex\DTO\Schema\SchemaReference;
use Hyperf\Apex\Enum\Interface\EnumInterface;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionIntersectionType;
use ReflectionNamedType;
use ReflectionParameter;
use ReflectionProperty;
use ReflectionType;
use ReflectionUnionType;
use RuntimeException;

/**
 * 由 DTO 反射产出 OpenAPI Schema 片段。
 *
 * 一律以 SchemaFragment 流转，不生产输出形态的数组 ——
 * 落成文档由 Compiler\SchemaFragmentTranslator 与编译器负责。
 */
final class DTOSchemaFactory
{
    /** 类型表达式内层允许出现的非类名类型，与 primitiveFragment() 显式列出的分支一致。 */
    private const PRIMITIVE_NAMES = [
        'string', 'int', 'integer', 'bool', 'boolean', 'float', 'number', 'numeric',
        'array', 'iterable', 'object', 'mixed', 'null',
    ];

    /** 只对特定 JSON 类型生效的关键字，用于合并后的死约束检查。 */
    private const TYPE_BOUND_KEYWORDS = [
        'string' => ['minLength', 'maxLength'],
        'array' => ['items', 'minItems', 'maxItems', 'uniqueItems', 'prefixItems', 'contains'],
        'object' => ['minProperties', 'maxProperties', 'additionalProperties', 'patternProperties', 'propertyNames'],
    ];

    /** @var array<class-string, SchemaFragment> */
    private array $schemas = [];

    /** @var array<string, SchemaFragment> 泛型实例化等合成组件，键就是组件名 */
    private array $specializations = [];

    /**
     * 注册组件并返回指向它的引用片段。先占位再填充，避免自引用 DTO 造成无限递归。
     */
    public function refFor(string $class): SchemaFragment
    {
        if (! isset($this->schemas[$class])) {
            $this->schemas[$class] = SchemaFragment::empty();
            $this->schemas[$class] = $this->objectFragment($class);
        }

        return self::refTo(self::componentName($class));
    }

    /**
     * 注册一个合成组件（如泛型 DTO 的具体实例化），并返回指向它的引用片段。
     *
     * 泛型 DTO 每种元素类型都是不同的结构，内联展开会在多个接口里重复同一份定义，
     * 注册成独立组件后既可复用，生成的客户端代码也能得到一个具名类型。
     */
    public function refForSpecialization(string $name, SchemaFragment $fragment): SchemaFragment
    {
        // 同名但结构不同意味着组件名没有把区分维度编码进去（例如漏了属性名），
        // 静默保留第一个会让第二个接口拿到它从不返回的结构。
        $existing = $this->specializations[$name] ?? null;
        if ($existing !== null && self::comparableValue($existing) !== self::comparableValue($fragment)) {
            throw new RuntimeException(sprintf('合成组件名 %s 对应了两种不同的结构。', $name));
        }

        $this->specializations[$name] = $fragment;

        return self::refTo($name);
    }

    /**
     * 已注册的组件，键为组件名。
     *
     * 组件名取短类名（对 $ref 可读性最好）。不同命名空间的同名 DTO 会产生同一个键，
     * 原先是后者静默覆盖前者、$ref 还指错；这里改为直接报错，
     * 把问题挡在构建期并交由人决定改名或引入前缀。
     *
     * @return array<string, SchemaFragment>
     */
    public function componentFragments(): array
    {
        $components = [];
        $sources = [];
        foreach ($this->schemas as $class => $fragment) {
            $name = self::componentName($class);
            if (isset($sources[$name])) {
                throw new RuntimeException(sprintf(
                    '组件名 %s 冲突：%s 与 %s 短类名相同，请重命名其中之一。',
                    $name,
                    $sources[$name],
                    $class
                ));
            }

            $sources[$name] = $class;
            $components[$name] = $fragment;
        }

        foreach ($this->specializations as $name => $fragment) {
            if (isset($sources[$name])) {
                throw new RuntimeException(sprintf('合成组件名 %s 与 %s 冲突。', $name, $sources[$name]));
            }

            $components[$name] = $fragment;
        }

        // 按名字排序：注册顺序跟着控制器扫描顺序走，而目录遍历顺序依赖文件系统的
        // readdir 语义。不排序的话同一份源码在不同文件系统上会产出不同的键顺序，
        // 而快照测试是逐字节比对的。
        ksort($components);

        return $components;
    }

    public static function componentName(string $class): string
    {
        return ComponentName::of($class);
    }

    /**
     * 由类型表达式解析出片段。
     *
     * `#[ArrayOf]`、`#[MapOf]`、方法级返回类型和 SchemaReference 都走这里，
     * 保证「同一个类型在任何位置都得到同一种表达」。接受 list<X>、array<string, X>、
     * X[] 等嵌套写法；无法表达的写法直接抛异常，而不是静默落成 type: string。
     */
    /**
     * @param bool $nested 该类型是否出现在泛型表达式内层（内层必须写完整类名，见 fragmentForLeaf）
     */
    public function fragmentForTypeName(string $type, bool $nested = false): SchemaFragment
    {
        return $this->fragmentForExpression(TypeExpression::parse($type), $nested);
    }

    /**
     * 已解析的类型表达式转片段。@return 注释需要先还原短类名再解析，因此单独暴露这个入口。
     */
    public function fragmentForExpression(TypeExpression $expression, bool $nested = false): SchemaFragment
    {
        return $this->fragmentFor($expression, $nested);
    }

    /**
     * 展开成内联对象片段（不注册组件）。
     *
     * @param array<string, SchemaFragment> $propertyOverrides
     */
    public function objectFragment(string $class, array $propertyOverrides = []): SchemaFragment
    {
        $reflection = new ReflectionClass($class);
        $parameters = [];
        foreach ($reflection->getConstructor()?->getParameters() ?? [] as $parameter) {
            $parameters[$parameter->getName()] = $parameter;
        }

        $properties = [];
        $required = [];
        foreach ($reflection->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
            $name = $property->getName();
            $parameter = $parameters[$name] ?? null;
            $fragment = self::withInferredDefault($this->propertyFragment($property), $parameter);
            // 覆盖会整体替换形状（响应信封的 data 由返回类型决定，属性自己推断出的
            // mixed/nullable 不该残留），但属性的 #[FieldDescription] 要带过去。
            $properties[$name] = isset($propertyOverrides[$name])
                ? self::withDescriptionFrom($fragment, $propertyOverrides[$name])
                : $fragment;
            if ($property->getAttributes(RequiredAttribute::class, ReflectionAttribute::IS_INSTANCEOF)
                || ($parameter && ! $parameter->isDefaultValueAvailable()
                    && ! $property->getAttributes(OptionalAttribute::class, ReflectionAttribute::IS_INSTANCEOF))) {
                $required[] = $name;
            }
        }

        return SchemaFragment::of(
            ['type' => 'object', 'properties' => $properties] + ($required ? ['required' => $required] : [])
        );
    }

    /**
     * mixed / void 不加类型约束；null 是 3.1 的独立类型。
     */
    public static function primitiveFragment(string $type): SchemaFragment
    {
        return SchemaFragment::of(match ($type) {
            'int', 'integer' => ['type' => 'integer'],
            'bool', 'boolean' => ['type' => 'boolean'],
            'float', 'number', 'numeric' => ['type' => 'number'],
            'array', 'iterable' => ['type' => 'array'],
            'object' => ['type' => 'object'],
            'mixed', 'void' => [],
            'null' => ['type' => 'null'],
            default => ['type' => 'string'],
        });
    }

    /**
     * 把构造器参数的默认值补成 default。
     *
     * 「可选」只说明字段可以不传，没说不传会发生什么 —— 消费方看不到 status 缺省是 1。
     * 只取非 null 的标量：`= null` 补出来的 `default: null` 与 nullable 重复，没有信息量；
     * 已经写了 #[DefaultValue] 的以 Attribute 为准。
     *
     * 公开给同为「请求参数」语义的调用方复用（`RequestBuilder` 的散装标量参数）：
     * 这条判据只能有一处实现，两份拷贝迟早会漂移。
     */
    public static function withInferredDefault(SchemaFragment $fragment, ?ReflectionParameter $parameter): SchemaFragment
    {
        if ($fragment->has('default') || $parameter === null || ! $parameter->isDefaultValueAvailable()) {
            return $fragment;
        }

        $default = $parameter->getDefaultValue();

        return is_scalar($default) ? $fragment->with('default', $default) : $fragment;
    }

    private static function comparableValue(mixed $value): mixed
    {
        if ($value instanceof SchemaFragment) {
            $value = $value->keywords();
        }

        return is_array($value) ? array_map(self::comparableValue(...), $value) : $value;
    }

    /**
     * 把被覆盖属性的字段说明带到覆盖后的片段上。
     */
    private static function withDescriptionFrom(SchemaFragment $base, SchemaFragment $override): SchemaFragment
    {
        $description = $base->get('description');

        return $override->has('description') || ! is_string($description)
            ? $override
            : $override->with('description', $description);
    }

    /**
     * 单个类型名（表达式的叶子）：枚举展开成 {key, name}，具体类出 $ref，其余按基础类型处理。
     *
     * $nested 为真表示这个叶子来自泛型表达式的内层。那个位置上的类名**必须写完整类名**——
     * PHP 不会对字符串里的类名做 use 解析，`'list<BarDTO>'` 拿不到 BarDTO 的命名空间。
     * 这种写法在文档、校验、水合三处都会退化成「按标量处理」，所以直接拒绝，
     * 而不是静默产出 type: string。
     *
     * 接口、抽象类和不合规枚举同样直接拒绝：它们没有确定的序列化形态，
     * 落到标量兜底会得到一个语法合法但语义骗人的 type: string。
     */
    private function fragmentForLeaf(string $type, bool $nested = false): SchemaFragment
    {
        if (interface_exists($type)) {
            throw new RuntimeException(sprintf(
                '接口类型 %s 没有确定的结构，无法生成 Schema。请改用具体的 DTO 类。',
                $type
            ));
        }

        if (! class_exists($type)) {
            if ($nested && ! in_array($type, self::PRIMITIVE_NAMES, true)) {
                throw new RuntimeException(sprintf(
                    '类型表达式的内层类型 %s 无法解析。类请写完整类名（如 \App\Foo\DTO\BarDTO），'
                    . '标量请用 %s 之一。',
                    $type,
                    implode(' / ', self::PRIMITIVE_NAMES)
                ));
            }

            return self::primitiveFragment($type);
        }

        if (is_a($type, EnumInterface::class, true)) {
            return self::enumFragment($type);
        }

        $reflection = new ReflectionClass($type);
        if ($reflection->isEnum()) {
            throw new RuntimeException(sprintf(
                '枚举 %s 没有实现 %s，无法确定它的序列化形态。',
                $type,
                EnumInterface::class
            ));
        }

        if ($reflection->isAbstract()) {
            throw new RuntimeException(sprintf(
                '抽象类 %s 没有确定的结构，无法生成 Schema。请改用具体的 DTO 类。',
                $type
            ));
        }

        // 判据是「有没有公开属性」而不是命名空间：DTO 一定有公开属性（含构造器提升），
        // 而 DateTimeImmutable、Carbon 这类值对象一个都没有，按标量兜底才合理。
        // 用命名空间前缀判断会让 App\ 之外的 DTO 静默退化成 type: string —— 那种错更难发现。
        return $reflection->getProperties(ReflectionProperty::IS_PUBLIC) !== []
            ? $this->refFor($type)
            : self::primitiveFragment($type);
    }

    private function fragmentFor(TypeExpression $expression, bool $nested): SchemaFragment
    {
        return match ($expression->kind) {
            TypeExpression::KIND_LIST => SchemaFragment::of([
                'type' => 'array',
                'items' => $this->fragmentFor($expression->item, true),
            ]),
            TypeExpression::KIND_MAP => SchemaFragment::of([
                'type' => 'object',
                'additionalProperties' => $this->fragmentFor($expression->item, true),
            ]),
            default => $this->fragmentForLeaf($expression->name, $nested),
        };
    }

    private static function refTo(string $componentName): SchemaFragment
    {
        return SchemaFragment::of(['ref' => '#/components/schemas/' . $componentName]);
    }

    private function propertyFragment(ReflectionProperty $property): SchemaFragment
    {
        $type = $property->getType();
        $fragment = $this->fragmentForType($type);

        if ($this->allowsNull($property, $type)) {
            $fragment = $fragment->with('nullable', true);
        }

        $array = $this->attribute($property, ArrayOfAttribute::class);
        if ($array instanceof ArrayOfAttribute) {
            // 元素类型走统一解析：枚举元素要展开成 {key, name}，而不是按类反射出 $ref。
            // nested=true：元素位置上的类名必须写完整类名（字符串里的短类名拿不到命名空间），
            // 写错要在这里报错 —— 否则它会静默落成 `items: {type: string}`，而校验与水合
            // 仍按「元素是对象」处理，同一份声明被三处读出三种结论。
            $items = $this->fragmentForTypeName($array->itemType(), true);
            foreach ($property->getAttributes() as $attribute) {
                $instance = $attribute->newInstance();
                if ($instance instanceof ItemSchemaAttribute) {
                    $items = $items->merge($instance->itemSchemaFragment());
                }
            }
            // 元素层同样要过一致性收口：`#[ArrayOf('string')]` + `#[ItemMinimum(1)]` 会产出
            // `items: {type: string, minimum: 1}` 这种不会被任何校验器执行的死约束，
            // 而属性级同样写法是会报错的 —— 两层判据必须一致。
            $fragment = $fragment->with('items', self::reconcile($this->describe($property) . '.items', $items));
        }

        // 按源码声明顺序依次合并，后声明的覆盖先声明的（#[MapOf] 依赖这一点）。
        $written = [];
        foreach ($property->getAttributes() as $attribute) {
            $instance = $attribute->newInstance();
            if (! $instance instanceof SchemaAttribute) {
                continue;
            }

            if ($instance instanceof TypedSchemaAttribute) {
                $this->assertPropertyType($property, $instance);
            }

            $addition = $instance->fragment();
            $this->rejectConflicts($property, $addition, $instance, $written);
            $fragment = $fragment->merge($addition);
        }

        $fragment = self::reconcile($this->describe($property), $fragment);

        // Attribute 只能给出类型引用，真正的 $ref 解析与组件注册在这里完成。
        return $this->resolveReferences($fragment);
    }

    /**
     * 两个 Attribute 争抢同一个关键字时报错。
     *
     * 浅覆盖让后声明的静默胜出：#[Format] 与 #[DateFormat] 抢 format、#[Pattern] 与
     * #[DateFormat] 抢 pattern，结果由声明顺序决定，而文档与校验各按自己那份走。
     * description 例外 —— 它的合并语义就是追加。
     *
     * @param array<string, array{value: mixed, attribute: class-string}> $written
     */
    private function rejectConflicts(ReflectionProperty $property, SchemaFragment $addition, SchemaAttribute $attribute, array &$written): void
    {
        foreach ($addition->keywords() as $keyword => $value) {
            if ($keyword === 'description' || $keyword === 'x') {
                continue;
            }

            if (array_key_exists($keyword, $written)) {
                // 写同一个关键字、值也完全一致（例如 #[MapOf] 与 #[PropertyCount] 都声明
                // type: object）：产出与声明顺序无关，不是冲突，不该拦。
                if (self::comparableValue($written[$keyword]['value']) === self::comparableValue($value)) {
                    continue;
                }

                throw new RuntimeException(sprintf(
                    '%s 上的 %s 与 %s 都在写 Schema 关键字 %s，产出取决于声明顺序。请只保留一个。',
                    $this->describe($property),
                    $written[$keyword]['attribute'],
                    $attribute::class,
                    $keyword
                ));
            }

            $written[$keyword] = ['value' => $value, 'attribute' => $attribute::class];
        }
    }

    /**
     * 只允许标注在特定 PHP 类型上的 Attribute 在这里收口。
     *
     * 判据必须是「源类型 => 目标类型」这一对，而不是目标类型：同样是出站成字符串，
     * int 过来无损可逆，float 过来会受 precision ini 影响写出 `(string) (0.1 + 0.2) === "0.3"`，
     * bool 过来会产出空串。后两者需要专门的序列化器而不是 cast，落进文档就是「语法合法但
     * 语义骗人」，所以在这里拒掉，而不是留给 code review。
     */
    private function assertPropertyType(ReflectionProperty $property, TypedSchemaAttribute $attribute): void
    {
        $type = $property->getType();
        $name = $type instanceof ReflectionNamedType ? $type->getName() : null;
        $allowed = $attribute->propertyTypes();

        if ($name !== null && in_array($name, $allowed, true)) {
            return;
        }

        throw new RuntimeException(sprintf(
            '%s 的 %s 只允许标注在 %s 类型的属性上，当前是 %s。',
            $this->describe($property),
            $attribute::class,
            implode(' / ', $allowed),
            $name ?? '未声明类型'
        ));
    }

    private function describe(ReflectionProperty $property): string
    {
        return $property->getDeclaringClass()->getName() . '::$' . $property->getName();
    }

    /**
     * 合并完成后的一致性收口。
     *
     * 浅覆盖只能纠正 type，纠正不了「换了 type 之后旧关键字还留着」和「两个关键字互相矛盾」。
     * 这里把这两类问题挡在构建期：能自动补齐的补齐，补不了的报错。
     */
    private static function reconcile(string $where, SchemaFragment $fragment): SchemaFragment
    {
        // enum 是穷尽的、对所有实例类型生效，所以可空字段的 enum 必须自己带上 null，
        // 否则 type 允许 null 而 enum 不允许，文档比服务端更严。
        $enum = $fragment->get('enum');
        if (is_array($enum) && $fragment->get('nullable') === true && ! in_array(null, $enum, true)) {
            $fragment = $fragment->with('enum', [...$enum, null]);
        }

        $type = $fragment->get('type');
        if (! is_string($type)) {
            // 没有确定的单一类型（mixed、联合、纯组合关键字）时无从校验，交给编译器。
            return $fragment;
        }

        foreach (self::TYPE_BOUND_KEYWORDS as $expected => $keywords) {
            if ($type === $expected) {
                continue;
            }

            foreach ($keywords as $keyword) {
                if ($fragment->has($keyword)) {
                    throw new RuntimeException(sprintf(
                        '%s 的 %s 与 type: %s 不匹配 —— 该关键字只对 %s 生效，'
                        . '留在这里是一条不会被任何校验器执行的死约束。',
                        $where,
                        $keyword,
                        $type,
                        $expected
                    ));
                }
            }
        }

        return $fragment;
    }

    /**
     * 递归把片段中的 SchemaReference 换成解析后的片段。
     */
    private function resolveReferences(SchemaFragment $fragment): SchemaFragment
    {
        $resolved = [];
        foreach ($fragment->keywords() as $keyword => $value) {
            $resolved[$keyword] = $this->resolveValue($value);
        }

        return SchemaFragment::of($resolved);
    }

    private function resolveValue(mixed $value): mixed
    {
        if ($value instanceof SchemaReference) {
            return $this->fragmentForReference($value->type);
        }

        if ($value instanceof SchemaFragment) {
            return $this->resolveReferences($value);
        }

        return is_array($value) ? array_map($this->resolveValue(...), $value) : $value;
    }

    /**
     * 显式写出的类型引用与推断出的类型走同一条解析路径。
     *
     * 按内层叶子处理（$nested = true）：Attribute 的类型参数是字符串，不参与 PHP 的
     * use 解析，写短类名或拼错就无从还原，必须报错而不是落成 type: string。
     */
    private function fragmentForReference(string $type): SchemaFragment
    {
        return $this->fragmentFor(TypeExpression::parse($type), true);
    }

    private function allowsNull(ReflectionProperty $property, ?ReflectionType $type): bool
    {
        if ($property->getAttributes(NonNullableAttribute::class, ReflectionAttribute::IS_INSTANCEOF)) {
            return false;
        }

        if ($type instanceof ReflectionNamedType) {
            return $type->allowsNull();
        }

        if (! $type instanceof ReflectionUnionType) {
            return false;
        }

        foreach ($type->getTypes() as $unionType) {
            if ($unionType instanceof ReflectionNamedType && $unionType->getName() === 'null') {
                return true;
            }
        }

        return false;
    }

    private function fragmentForType(?ReflectionType $type): SchemaFragment
    {
        if ($type instanceof ReflectionNamedType) {
            return $this->fragmentForTypeName($type->getName());
        }

        // 交集类型（A&B）没有可表达的结构，给空片段而不是谎报成字符串。
        if ($type instanceof ReflectionIntersectionType) {
            return SchemaFragment::empty();
        }

        if (! $type instanceof ReflectionUnionType) {
            return SchemaFragment::of(['type' => 'string']);
        }

        $fragments = [];
        foreach ($type->getTypes() as $unionType) {
            if ($unionType instanceof ReflectionNamedType && $unionType->getName() !== 'null') {
                $fragments[] = $this->fragmentForType($unionType);
            }
        }

        return count($fragments) === 1 ? $fragments[0] : SchemaFragment::of(['oneOf' => $fragments]);
    }

    /**
     * 取属性上第一个实现了 $interface 的 Attribute。
     *
     * `#[ArrayOf]` 声明成了 IS_REPEATABLE（方法级要按 property 区分多个），
     * 属性级重复标注则是笔误 —— 静默只取第一个会丢掉其余声明，因此直接报错。
     */
    private function attribute(ReflectionProperty $property, string $interface): ?object
    {
        $found = null;
        foreach ($property->getAttributes() as $attribute) {
            $instance = $attribute->newInstance();
            if (! $instance instanceof $interface) {
                continue;
            }

            if ($found !== null) {
                throw new RuntimeException(sprintf(
                    '%s 上有多个 %s，只有第一个会生效。请只保留一个。',
                    $this->describe($property),
                    $interface
                ));
            }

            $found = $instance;
        }

        return $found;
    }

    /**
     * 项目枚举经 EnumTrait 序列化为 {key, name} 对象，因此响应侧的 Schema 是对象而非标量。
     *
     * @param class-string<EnumInterface> $enum
     */
    private static function enumFragment(string $enum): SchemaFragment
    {
        $cases = $enum::cases();
        $values = array_map(static fn (EnumInterface $case): int|string => $case->value, $cases);
        $names = array_map(static fn (EnumInterface $case): string => $case->getMessage(), $cases);
        $keyType = $values !== [] && is_int($values[0]) ? 'integer' : 'string';

        return SchemaFragment::of([
            'type' => 'object',
            'properties' => [
                'key' => SchemaFragment::of(['type' => $keyType, 'enum' => $values]),
                'name' => SchemaFragment::of(['type' => 'string', 'enum' => $names]),
            ],
            'required' => ['key', 'name'],
            'x' => [
                'enum-descriptions' => $names,
                'enum-varnames' => array_map(static fn (EnumInterface $case): string => $case->name, $cases),
            ],
        ]);
    }
}
