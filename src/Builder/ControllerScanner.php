<?php

declare(strict_types=1);

namespace Hyperf\Apex\ApiDoc\Builder;

use Hyperf\Apex\ApiDoc\Controller\ApiDocController;
use Hyperf\HttpServer\Annotation\Controller;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use RuntimeException;

/**
 * 发现待收录的控制器，并按 api_doc.excluded 过滤。
 */
final class ControllerScanner
{
    /** @param list<string> $excludedPatterns 支持 * 通配的类名或「类名::方法名」 */
    public function __construct(private readonly array $excludedPatterns)
    {
    }

    /**
     * @return list<ReflectionClass<object>>
     */
    public function controllers(string $appPath): array
    {
        $controllers = [];
        foreach ($this->candidateClasses($appPath) as $class) {
            // 文档控制器自身永不进业务接口文档。
            if ($class === ApiDocController::class || $this->isExcluded($class)) {
                continue;
            }

            $reflection = new ReflectionClass($class);
            if ($reflection->getAttributes(Controller::class) !== []) {
                $controllers[] = $reflection;
            }
        }

        return $controllers;
    }

    public function isExcluded(string $target): bool
    {
        foreach ($this->excludedPatterns as $pattern) {
            if (fnmatch($pattern, $target, FNM_NOESCAPE)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<class-string>
     */
    private function candidateClasses(string $appPath): array
    {
        $classes = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($appPath));
        foreach ($iterator as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php' || ! str_contains($file->getPathname(), '/Controller/')) {
                continue;
            }

            $contents = file_get_contents($file->getPathname());
            if ($contents === false) {
                throw new RuntimeException('无法读取控制器文件：' . $file->getPathname());
            }

            if (! preg_match('/namespace\s+([^;]+);.*?class\s+(\w+)/s', $contents, $matches)) {
                // 静默跳过会让一个控制器整体从文档里消失，且没有任何迹象。
                throw new RuntimeException('无法从文件中解析出类名：' . $file->getPathname());
            }

            /** @var class-string $class */
            $class = $matches[1] . '\\' . $matches[2];
            $classes[] = $class;
        }

        // 目录遍历顺序依赖文件系统的 readdir 语义，排序后组件与错误信息才跨环境稳定。
        sort($classes);

        return $classes;
    }
}
