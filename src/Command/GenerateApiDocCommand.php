<?php

declare(strict_types=1);

namespace Hyperf\Apex\ApiDoc\Command;

use Hyperf\Apex\ApiDoc\OpenApiGeneratorFactory;
use Hyperf\Apex\ApiDoc\Support\ApiDocPaths;
use Hyperf\Command\Annotation\Command;
use Hyperf\Command\Command as HyperfCommand;
use Hyperf\Contract\ConfigInterface;

#[Command(name: 'api-doc:generate', description: 'Generate OpenAPI JSON from controllers and DTOs.')]
final class GenerateApiDocCommand extends HyperfCommand
{
    public function __construct(
        private readonly OpenApiGeneratorFactory $generators,
        private readonly ConfigInterface $config,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $generator = $this->generators->make();
        $specification = $generator->specification($this->appPath());

        // 编译器自带的规格校验：把结构性错误挡在构建期，而不是等下游导入时才发现。
        // warning 同样视为失败 —— 上游把「type: array 但没有 items」这类骗人的文档
        // 归到 warning，放过它就等于放过一份说了谎的契约。
        $failed = false;
        foreach ($generator->validate($specification) as $entry) {
            $this->error(sprintf('[%s] %s', $entry['level'], $entry['message']));
            $failed = true;
        }
        if ($failed) {
            return self::FAILURE;
        }

        $directory = $this->outputPath();
        // 用 @ 抑制 mkdir 的警告：建不出来的处理在下一行显式给出。
        // 不抑制的话，把警告当异常的应用（set_error_handler → ErrorException）会拿到一条
        // 「mkdir(): No such file or directory」，而不是这句能指出问题路径的提示。
        if (! is_dir($directory) && ! @mkdir($directory, 0775, true) && ! is_dir($directory)) {
            $this->error('无法创建目录：' . $directory);
            return self::FAILURE;
        }

        $path = ApiDocPaths::outputFile($this->config, ApiDocPaths::OPENAPI_FILE);
        $json = json_encode(
            $generator->compile($specification),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        );
        file_put_contents($path, $json . PHP_EOL);
        $this->info('OpenAPI 文档已生成：' . $path);

        return self::SUCCESS;
    }

    /**
     * 待扫描的应用目录。默认 `BASE_PATH/app`（Hyperf 约定），
     * 代码不放在 `app/` 下的工程用 `api_doc.app_path` 指向实际目录。
     */
    private function appPath(): string
    {
        return self::path(
            $this->config->get('api_doc.app_path'),
            static fn (): string => BASE_PATH . '/app',
        );
    }

    /**
     * 产物目录。默认 `BASE_PATH/runtime/api-doc`，需要用别的目录承接时改 `api_doc.output_path`。
     *
     * 与对外出口（ApiDocController）共用 ApiDocPaths，两处不可能解析出不同结果。
     */
    private function outputPath(): string
    {
        return ApiDocPaths::outputDirectory($this->config);
    }

    /**
     * 默认值是闭包而不是字符串：`BASE_PATH` 由应用引导脚本定义，
     * 直接写成实参会在每次调用时都求值一次 —— 配了路径也照样要求这个常量存在。
     */
    private static function path(mixed $value, callable $default): string
    {
        return is_string($value) && $value !== '' ? rtrim($value, '/') : (string) $default();
    }
}
