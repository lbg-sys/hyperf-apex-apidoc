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
     * 收集候选类名。
     *
     * 两条曾经的规则都会出错：要求路径里含 `/Controller/` 会让放在别的目录下的控制器从文档里
     * 静默消失（文档整体为空也没有任何迹象），而用正则从文件里抽类名遇到 `app/Controller/`
     * 下的 trait / interface / enum 会直接抛「无法解析出类名」，把整个生成命令拦下来。
     * 现在扫描全部 `.php`，逐文件用 token 判定是否声明了类，非类文件跳过。
     *
     * @return list<class-string>
     */
    private function candidateClasses(string $appPath): array
    {
        if (! is_dir($appPath)) {
            // 原生 RecursiveDirectoryIterator 的报错是一条与配置无关的 UnexpectedValueException。
            throw new RuntimeException(sprintf('api_doc.app_path 指向的目录不存在：%s', $appPath));
        }

        $classes = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($appPath));
        foreach ($iterator as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $class = self::classInFile($file->getPathname());
            if ($class !== null) {
                $classes[] = $class;
            }
        }

        // 目录遍历顺序依赖文件系统的 readdir 语义，排序后组件与错误信息才跨环境稳定。
        sort($classes);

        return $classes;
    }

    /**
     * 取出文件声明的类名；没有类声明（trait / interface / enum / 只有函数）时返回 null。
     *
     * 用 token 而不是正则：正则会把注释与字符串里的 class 字样、`::class` 具体化、
     * 匿名类 `new class` 都当成类声明。
     *
     * @return null|class-string
     */
    private static function classInFile(string $path): ?string
    {
        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new RuntimeException('无法读取源文件：' . $path);
        }

        $namespace = '';
        $tokens = token_get_all($contents);
        $count = count($tokens);
        for ($index = 0; $index < $count; ++$index) {
            $token = $tokens[$index];
            if (! is_array($token)) {
                continue;
            }

            if ($token[0] === T_NAMESPACE) {
                $namespace = self::readNamespace($tokens, $index, $count);
                continue;
            }

            if ($token[0] !== T_CLASS) {
                continue;
            }

            $previous = $tokens[$index - 1] ?? null;
            if (is_array($previous) && in_array($previous[0], [T_DOUBLE_COLON, T_NEW], true)) {
                // `Foo::class` 与匿名类都不是这里的类声明。
                continue;
            }

            for ($cursor = $index + 1; $cursor < $count; ++$cursor) {
                $next = $tokens[$cursor];
                if (is_array($next) && $next[0] === T_STRING) {
                    /* @var class-string $class */
                    return ltrim($namespace . '\\' . $next[1], '\\');
                }
                if ($next === '{') {
                    break;
                }
            }
        }

        return null;
    }

    /**
     * @param list<array{int, string, int}|string> $tokens
     */
    private static function readNamespace(array $tokens, int $index, int $count): string
    {
        $namespace = '';
        for ($cursor = $index + 1; $cursor < $count; ++$cursor) {
            $token = $tokens[$cursor];
            if ($token === ';' || $token === '{') {
                break;
            }
            if (is_array($token) && in_array($token[0], [T_STRING, T_NAME_QUALIFIED, T_NS_SEPARATOR], true)) {
                $namespace .= $token[1];
            }
        }

        return $namespace;
    }
}
