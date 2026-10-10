<?php

declare(strict_types=1);

namespace Hyperf\Apex\ApiDoc\Command;

use Hyperf\Apex\ApiDoc\Apifox\ApifoxDocumentBuilder;
use Hyperf\Command\Annotation\Command;
use Hyperf\Command\Command as HyperfCommand;
use Hyperf\Contract\ConfigInterface;
use JsonException;

#[Command(name: 'api-doc:generate-apifox', description: 'Generate the Apifox native document for WebSocket contracts.')]
final class GenerateApifoxDocCommand extends HyperfCommand
{
    public function __construct(
        private readonly ApifoxDocumentBuilder $builder,
        private readonly ConfigInterface $config,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        try {
            $json = json_encode(
                $this->builder->build(),
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
            );
        } catch (JsonException $exception) {
            // 序列化失败说明 builder 产出了非法结构，必须报错而不是写出一份半成品文档。
            $this->error('Apifox 文档序列化失败：' . $exception->getMessage());

            return self::FAILURE;
        }

        $directory = $this->outputPath();
        // 同 OpenAPI 生成命令：警告用 @ 抑制，失败由下一行的显式分支报告。
        if (! is_dir($directory) && ! @mkdir($directory, 0775, true) && ! is_dir($directory)) {
            $this->error('无法创建目录：' . $directory);

            return self::FAILURE;
        }

        $path = $directory . '/apifox.json';
        file_put_contents($path, $json . PHP_EOL);
        $this->info('Apifox 文档已生成：' . $path);

        return self::SUCCESS;
    }

    /**
     * 产物目录。默认 `BASE_PATH/runtime/api-doc`，与 OpenAPI 生成命令共用 `api_doc.output_path`。
     */
    private function outputPath(): string
    {
        $value = $this->config->get('api_doc.output_path');
        if (is_string($value) && $value !== '') {
            return rtrim($value, '/');
        }

        // BASE_PATH 由应用引导脚本定义，只有真的要用默认值时才读它。
        return BASE_PATH . '/runtime/api-doc';
    }
}
