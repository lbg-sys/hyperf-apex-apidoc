<?php

declare(strict_types=1);

namespace Hyperf\Apex\ApiDoc;

use Hyperf\Apex\ApiDoc\Command\GenerateApiDocCommand;

class ConfigProvider
{
    /**
     * @return array<string, mixed>
     */
    public function __invoke(): array
    {
        return [
            'dependencies' => [],
            // Apifox（WebSocket 契约）导出命令由 hyperf-apex/apidoc-apifox 注册：
            // 它要读长连接契约，因此那一个包才依赖 hyperf-apex/websocket。
            'commands' => [
                GenerateApiDocCommand::class,
            ],
            'listeners' => [],
            'annotations' => [
                'scan' => [
                    'paths' => [
                        __DIR__,
                    ],
                ],
            ],
            'publish' => [
                [
                    'id' => 'config',
                    'description' => 'The configuration files for apex api-doc generator.',
                    'source' => __DIR__ . '/../publish/',
                    'destination' => BASE_PATH . '/config/autoload/',
                ],
            ],
        ];
    }
}
