<?php

declare(strict_types=1);

namespace Hyperf\Apex\ApiDoc\Apifox;

use Hyperf\Apex\ApiDoc\DTOSchemaFactory;
use Hyperf\Apex\DTO\Schema\SchemaFragment;
use Hyperf\Apex\Enum\ErrorCode;
use Hyperf\Apex\Support\ClassNamePattern;
use Hyperf\Apex\Support\StringList;
use Hyperf\Apex\WebSocket\Definition\WsGatewayDefinition;
use Hyperf\Apex\WebSocket\Definition\WsRouteDefinition;
use Hyperf\Apex\WebSocket\Protocol\WsBuiltinEvent;
use Hyperf\Apex\WebSocket\Registry\WsHandlerRegistry;
use Hyperf\Contract\ConfigInterface;
use stdClass;

/**
 * 渲染 WebSocket 契约的 Markdown（事实层）。
 *
 * WebSocket 接口在 Apifox 里没有数据结构的位置（官方 FAQ：不支持请求 / 响应示例，Mock 库也不支持），
 * 能装契约的槽位只有 description，所以字段表、枚举取值、错误码全部渲染成 Markdown 放在这里。
 *
 * 字段说明复用 DTOSchemaFactory：#[FieldDescription]、#[Length]、#[Minimum]、#[EnumValue]
 * 等 Attribute 会自动生效，不需要在文档里手工维护第二份。
 */
final class WsEventMarkdownRenderer
{
    /** 枚举字段在文档里的类型占位：响应侧是 {key, name} 对象而不是标量。 */
    private const ENUM_TYPE = '`{key, name}`';

    private readonly DTOSchemaFactory $schemas;

    private ?string $eventSection = null;

    public function __construct(
        private readonly WsHandlerRegistry $handlers,
        private readonly ConfigInterface $config,
    ) {
        $this->schemas = new DTOSchemaFactory();
    }

    /**
     * 信封示例，作为 Apifox 里那条「每个接口只有一个消息模板」的内容。
     *
     * 用 sys.ping 而不是某个业务事件：任何入口都接受它，不会因为接了新入口或改了业务事件而过期。
     */
    public static function messageTemplate(): string
    {
        return '{"id":"1","event":"sys.ping","data":{}}';
    }

    public function render(WsGatewayDefinition $gateway): string
    {
        return implode("\n\n", [
            $this->header($gateway),
            $this->envelopeSection(),
            $this->builtinSection(),
            $this->eventSection(),
            $this->errorSection(),
        ]);
    }

    private function header(WsGatewayDefinition $gateway): string
    {
        return implode("\n", [
            '## ' . $gateway->displayName(),
            '',
            '- 连接路径：`' . $gateway->path . '`（host 与 scheme 由 Apifox 环境决定，`ws://` 或 `wss://`）',
            '- 接入端标识：`' . $gateway->endpoint . '`（服务端推送寻址用，客户端不感知）',
            '- 握手鉴权：`token` 通过 query 参数或 `Sec-WebSocket-Protocol` 子协议传递；'
                . '校验失败返回 401，连接不建立',
            '- 心跳：服务端 60 秒无数据判定超时并关闭连接，客户端应在超时前发 `sys.ping`',
        ]);
    }

    private function envelopeSection(): string
    {
        return implode("\n", [
            '## 统一信封',
            '',
            '一条连接承载所有事件，业务路由靠 `event` 字段，不靠 URL。',
            '',
            '| 方向 | 结构 |',
            '| --- | --- |',
            '| 客户端 → 服务端 | `{ "id": "<唯一标识>", "event": "<事件名>", "data": { ... } }` |',
            '| 服务端 → 客户端（响应） | `{ "id": "<同请求>", "event": "<同请求>", "code": 0, "message": "成功", "data": { ... } }` |',
            '| 服务端 → 客户端（推送） | `{ "id": "<新生成>", "event": "<事件名>", "type": "push", "data": { ... } }` |',
            '',
            '- `id` 由客户端生成并原样回传，用于匹配请求与响应；',
            '- 响应与推送靠 `type` 区分，缺省视为响应；',
            '- `code` / `message` 复用 HTTP 侧的 ErrorCode，`0` 表示成功；',
            '- 业务异常不会断开连接，一律以错误信封返回。',
        ]);
    }

    private function builtinSection(): string
    {
        $rows = ['| 事件 | 方向 | 说明 |', '| --- | --- | --- |'];
        foreach (WsBuiltinEvent::cases() as $event) {
            $rows[] = sprintf(
                '| `%s` | %s | %s |',
                $event->value,
                $event->isClientInitiated() ? '客户端 → 服务端' : '服务端 → 客户端',
                $event->getMessage(),
            );
        }

        return implode("\n", [
            '## 内置事件',
            '',
            ...$rows,
            '',
            '`sys.subscribe` / `sys.unsubscribe` 的 data 为 `{ "room": "<房间名>" }`，'
                . '房间名限 1-64 位字母、数字与 `_ . : -`。',
        ]);
    }

    private function eventSection(): string
    {
        if ($this->eventSection !== null) {
            return $this->eventSection;
        }

        $excluded = $this->excluded();

        $definitions = [];
        foreach ($this->handlers->all() as $event => $definition) {
            // 事件与入口无关（任何接入端都能调所有事件），所以入口被排除不代表事件也被排除 ——
            // 这里必须再按同一份清单过滤一次，否则测试探针会混进 admin/device 的契约文档。
            if (ClassNamePattern::matches($excluded, $definition->handlerClass)) {
                continue;
            }

            $definitions[$event] = $definition;
        }

        if ($definitions === []) {
            return $this->eventSection = "## 业务事件\n\n当前没有注册任何业务事件（`#[WsRoute]`）。";
        }

        $sections = ['## 业务事件'];
        foreach ($definitions as $definition) {
            $sections[] = $this->event($definition);
        }

        return $this->eventSection = implode("\n\n", $sections);
    }

    /**
     * @return list<string>
     */
    private function excluded(): array
    {
        return StringList::of($this->config->get('websocket.excluded', []));
    }

    private function event(WsRouteDefinition $definition): string
    {
        $lines = [
            '### `' . $definition->event . '`',
            '',
            '- 处理器：`' . $definition->handlerId() . '`',
            '',
            '**请求**（`' . self::shortName($definition->payloadClass) . '`）',
            '',
            ...$this->fieldTable($definition->payloadClass),
            '',
        ];

        if ($definition->responseClass === null) {
            $lines[] = '**响应**：无响应体（`data` 为空）。';
        } else {
            $lines[] = '**响应**（`' . self::shortName($definition->responseClass) . '`）';
            $lines[] = '';
            $lines = [...$lines, ...$this->fieldTable($definition->responseClass)];
        }

        $lines[] = '';
        $lines[] = '**请求示例**（`data` 为结构示意，取值以字段表为准）';
        $lines[] = '';
        $lines[] = '```json';
        $lines[] = (string) json_encode([
            'id' => '1',
            'event' => $definition->event,
            'data' => $this->sample($definition->payloadClass),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $lines[] = '```';

        return implode("\n", $lines);
    }

    private function errorSection(): string
    {
        $rows = ['| code | 含义 | 触发场景 |', '| --- | --- | --- |'];
        foreach ($this->errorCodes() as $code) {
            $rows[] = sprintf('| `%d` | %s | %s |', $code->value, $code->getMessage(), $this->scenario($code));
        }

        return implode("\n", [
            '## 错误码',
            '',
            ...$rows,
            '',
            '错误码与 HTTP 侧一致。任何错误都不会断开连接，除了握手阶段的 401。',
        ]);
    }

    /**
     * @return list<ErrorCode>
     */
    private function errorCodes(): array
    {
        return [
            ErrorCode::SUCCESS,
            ErrorCode::INVALID_ARGUMENT,
            ErrorCode::VALIDATION_FAILED,
            ErrorCode::UNAUTHENTICATED,
            ErrorCode::RESOURCE_NOT_FOUND,
            ErrorCode::RESOURCE_CONFLICT,
            ErrorCode::INTERNAL_ERROR,
        ];
    }

    private function scenario(ErrorCode $code): string
    {
        return match ($code) {
            ErrorCode::SUCCESS => '—',
            ErrorCode::INVALID_ARGUMENT => '报文不是 JSON、缺少 `event`、事件未注册、房间名不合法',
            ErrorCode::VALIDATION_FAILED => 'payload 字段未通过 DTO 校验，`data.errors` 给出明细',
            ErrorCode::UNAUTHENTICATED => '事件需要用户身份，而连接是设备端；或握手的 token 无效',
            ErrorCode::RESOURCE_NOT_FOUND => '目标资源不存在或不属于当前用户',
            ErrorCode::RESOURCE_CONFLICT => '房间成员已达上限、单连接房间数超限',
            ErrorCode::INTERNAL_ERROR => '未预期异常，服务端已记录日志，连接保持',
            default => '其他业务错误',
        };
    }

    /**
     * @return list<string>
     */
    private function fieldTable(string $dtoClass): array
    {
        $lines = ['| 字段 | 类型 | 必填 | 说明 |', '| --- | --- | --- | --- |'];
        foreach ($this->fields($dtoClass) as $field) {
            $lines[] = sprintf(
                '| `%s` | %s | %s | %s |',
                $field['name'],
                $field['type'],
                $field['required'] ? '是' : '否',
                $field['description'],
            );
        }

        return $lines;
    }

    /**
     * @return list<array{name: string, type: string, required: bool, description: string}>
     */
    private function fields(string $dtoClass): array
    {
        $object = $this->schemas->objectFragment($dtoClass);
        $properties = $object->get('properties', []);
        $required = $object->get('required', []);

        $rows = [];
        foreach (is_array($properties) ? $properties : [] as $name => $fragment) {
            if (! $fragment instanceof SchemaFragment) {
                continue;
            }

            $rows[] = [
                'name' => (string) $name,
                'type' => $this->type($fragment),
                'required' => is_array($required) && in_array($name, $required, true),
                'description' => $this->describe($fragment),
            ];
        }

        return $rows;
    }

    private function type(SchemaFragment $fragment): string
    {
        $ref = $fragment->get('ref');
        if (is_string($ref)) {
            return self::shortName(str_replace('#/components/schemas/', '', $ref));
        }

        $extension = $fragment->get('x');
        if (is_array($extension) && isset($extension['enum-varnames'])) {
            // 响应侧的枚举经 EnumTrait 序列化成 {key, name} 对象，不是标量。
            return self::ENUM_TYPE;
        }

        $type = $fragment->get('type');
        if ($type === 'array') {
            $items = $fragment->get('items');

            return 'array<' . ($items instanceof SchemaFragment ? $this->type($items) : 'mixed') . '>';
        }

        if ($type === 'object') {
            $additional = $fragment->get('additionalProperties');

            return $additional instanceof SchemaFragment
                ? 'map<string, ' . $this->type($additional) . '>'
                : 'object';
        }

        $name = is_string($type) ? $type : 'mixed';

        return $fragment->get('nullable') === true ? $name . '|null' : $name;
    }

    private function describe(SchemaFragment $fragment): string
    {
        $parts = [];
        $description = $fragment->get('description');
        if (is_string($description) && $description !== '') {
            $parts[] = trim($description);
        }

        $parts = [...$parts, ...$this->constraints($fragment)];
        if ($parts === []) {
            return '—';
        }

        // 表格单元格不能有换行：EnumValue 追加的可选值说明本身是换行的。
        return str_replace(["\r\n", "\n"], '<br>', implode('；', $parts));
    }

    /**
     * @return list<string>
     */
    private function constraints(SchemaFragment $fragment): array
    {
        $constraints = [];
        if ($fragment->has('minLength') || $fragment->has('maxLength')) {
            $constraints[] = sprintf(
                '长度 %s-%s',
                $fragment->get('minLength', '不限'),
                $fragment->get('maxLength', '不限'),
            );
        }
        if ($fragment->has('minItems') || $fragment->has('maxItems')) {
            $constraints[] = sprintf(
                '元素数 %s-%s',
                $fragment->get('minItems', '不限'),
                $fragment->get('maxItems', '不限'),
            );
        }
        if ($fragment->has('minimum') || $fragment->has('maximum')) {
            $constraints[] = sprintf(
                '取值范围 %s-%s',
                $fragment->get('minimum', '不限'),
                $fragment->get('maximum', '不限'),
            );
        }
        if ($fragment->has('pattern')) {
            $constraints[] = '格式 ' . (string) $fragment->get('pattern');
        }
        if ($fragment->has('default')) {
            $constraints[] = '默认值 ' . (string) json_encode($fragment->get('default'), JSON_UNESCAPED_UNICODE);
        }
        if ($fragment->get('deprecated') === true) {
            $constraints[] = '已废弃';
        }

        return $constraints;
    }

    /**
     * 结构示意用的示例值：类型对得上就够，嵌套对象一律留空对象。
     *
     * @return array<string, mixed>
     */
    private function sample(string $dtoClass): array
    {
        $sample = [];
        foreach ($this->fields($dtoClass) as $field) {
            $sample[$field['name']] = $this->sampleValue($field['type']);
        }

        return $sample;
    }

    private function sampleValue(string $type): mixed
    {
        /*
         * 枚举字段是 {key, name} 对象、映射在 JSON 里也是对象。
         * 两者都给 [] 的话，示例（空列表）与同一行的类型列（对象）自相矛盾 ——
         * 而「示例与字段表打架」正是这份文档最容易被误读的地方。
         */
        if ($type === self::ENUM_TYPE) {
            return ['key' => 'value', 'name' => '说明'];
        }

        if (str_starts_with($type, 'map<')) {
            return new stdClass();
        }

        return match (true) {
            str_starts_with($type, 'integer') => 0,
            str_starts_with($type, 'number') => 0,
            str_starts_with($type, 'boolean') => true,
            default => str_starts_with($type, 'string') ? '' : [],
        };
    }

    private static function shortName(string $class): string
    {
        $position = strrpos($class, '\\');

        return $position === false ? $class : substr($class, $position + 1);
    }
}
