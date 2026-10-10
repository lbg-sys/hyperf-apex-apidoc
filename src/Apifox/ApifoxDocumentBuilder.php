<?php

declare(strict_types=1);

namespace Hyperf\Apex\ApiDoc\Apifox;

use Hyperf\Apex\Support\ClassNamePattern;
use Hyperf\Apex\Support\StringList;
use Hyperf\Apex\WebSocket\Definition\WsGatewayDefinition;
use Hyperf\Apex\WebSocket\Registry\WsGatewayScanner;
use Hyperf\Contract\ConfigInterface;

/**
 * 组装 Apifox 原生格式文档（webSocketCollection）。
 *
 * 为什么不用 OpenAPI：它是「一次调用 = 一个 operation」的模型，表达不了「一次连接 + N 条双向消息」，
 * Apifox 导入 OpenAPI 只会产出 HTTP 接口，GET /ws 会被当成普通 HTTP 请求发出去。
 * Apifox 原生格式顶层有 webSocketCollection，能生成真正的 WebSocket 接口，而且支持 URL 数据源定时导入。
 *
 * 顶层刻意只有三个键（apifoxProject / $schema / webSocketCollection）：不含 projectSetting、
 * moduleSettings、apiCollection，因此不存在「导错项目」与「清空现有 HTTP 接口」的风险。
 */
final class ApifoxDocumentBuilder
{
    public function __construct(
        private readonly WsGatewayScanner $gateways,
        private readonly WsEventMarkdownRenderer $markdown,
        private readonly ConfigInterface $config,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function build(): array
    {
        $excluded = $this->excluded();

        $items = [];
        foreach ($this->gateways->all() as $definition) {
            // 测试入口等不对外：只从文档里去掉，路由照旧注册，否则连不上就没法手工验证。
            if (ClassNamePattern::matches($excluded, $definition->class)) {
                continue;
            }

            $items[] = $this->item($definition);
        }

        return [
            'apifoxProject' => '1.0.0',
            '$schema' => ['app' => 'apifox', 'type' => 'project', 'version' => '1.2.0'],
            'webSocketCollection' => [
                [
                    'name' => $this->folder(),
                    'description' => '',
                    'items' => $items,
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function item(WsGatewayDefinition $definition): array
    {
        return [
            'name' => $definition->displayName(),
            'api' => [
                'id' => self::stableId($definition),
                'path' => $definition->path,
                // 握手参数不生成：token 每个环境不同，在 Apifox 环境变量里配一次更合理。
                'parameters' => ['query' => [], 'path' => [], 'cookie' => [], 'header' => []],
                'requestBody' => [
                    'parameters' => [],
                    'message' => WsEventMarkdownRenderer::messageTemplate(),
                    'messageType' => 'json',
                ],
                'description' => $this->markdown->render($definition),
                'tags' => $this->tags(),
                'status' => $this->status(),
            ],
        ];
    }

    /**
     * 稳定 id：重复导入必须匹配到同一条接口，否则每导一次就多一条。
     *
     * 接口唯一标识到底取 id 还是 path 尚未验证（WS 接口没有 HTTP method），
     * 生成稳定 id 是成本最低的降风险手段，首次导入后需要人工核对有没有产生重复。
     */
    private static function stableId(WsGatewayDefinition $definition): string
    {
        return 'ws-' . substr(hash('sha256', $definition->endpoint . '@' . $definition->path), 0, 32);
    }

    private function folder(): string
    {
        return $this->string('api_doc.apifox.folder', 'WebSocket');
    }

    /**
     * 需要排除的入口类，语义见 config/autoload/websocket.php。
     *
     * @return list<string>
     */
    private function excluded(): array
    {
        return StringList::of($this->config->get('websocket.excluded', []));
    }

    private function status(): string
    {
        return $this->string('api_doc.apifox.status', 'developing');
    }

    /**
     * @return list<string>
     */
    private function tags(): array
    {
        $tags = $this->config->get('api_doc.apifox.tags', ['WebSocket']);
        if (! is_array($tags)) {
            return ['WebSocket'];
        }

        return array_values(array_filter($tags, static fn (mixed $tag): bool => is_string($tag) && $tag !== ''));
    }

    private function string(string $path, string $default): string
    {
        $value = $this->config->get($path, $default);

        return is_string($value) && $value !== '' ? $value : $default;
    }
}
