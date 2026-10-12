<?php

declare(strict_types=1);

namespace Hyperf\Apex\ApiDoc\Support;

use Hyperf\Contract\ConfigInterface;

/**
 * 文档产物路径的唯一解析点。
 *
 * 生成命令（OpenAPI / Apifox）与对外出口必须用同一份解析：出口曾经把路径写死成
 * `BASE_PATH/runtime/api-doc`，应用一旦按 `api_doc.output_path` 把产物挪走，
 * 生成命令报「已生成」、接口却一直返回「文档尚未生成」，而两者之间没有任何线索可循。
 */
final class ApiDocPaths
{
    public const OPENAPI_FILE = 'openapi.json';

    public const APIFOX_FILE = 'apifox.json';

    private function __construct()
    {
    }

    /**
     * 产物目录：`api_doc.output_path`，默认 `BASE_PATH/runtime/api-doc`。
     */
    public static function outputDirectory(ConfigInterface $config): string
    {
        $configured = $config->get('api_doc.output_path');

        // BASE_PATH 由应用引导脚本定义：默认值只在真的要用时才求值，
        // 直接写成常量表达式会让「配了路径」的场景也依赖这个常量存在。
        return is_string($configured) && $configured !== ''
            ? rtrim($configured, '/')
            : BASE_PATH . '/runtime/api-doc';
    }

    public static function outputFile(ConfigInterface $config, string $fileName): string
    {
        return self::outputDirectory($config) . '/' . $fileName;
    }
}
