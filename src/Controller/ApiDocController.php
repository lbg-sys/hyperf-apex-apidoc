<?php

declare(strict_types=1);

namespace Hyperf\Apex\ApiDoc\Controller;

use Hyperf\Apex\Http\Controller\AbstractController;
use Hyperf\Apex\Security\Attribute\PublicAccess;
use Hyperf\HttpServer\Annotation\Controller;
use Hyperf\HttpServer\Annotation\GetMapping;
use Hyperf\HttpServer\Contract\ResponseInterface as HttpResponseInterface;
use Psr\Http\Message\ResponseInterface;

#[Controller(prefix: '/api_doc')]
final class ApiDocController extends AbstractController
{
    private const OPENAPI_FILE = '/runtime/api-doc/openapi.json';

    private const APIFOX_FILE = '/runtime/api-doc/apifox.json';

    public function __construct(private readonly HttpResponseInterface $response)
    {
    }

    #[GetMapping(path: 'get_openapi')]
    #[PublicAccess]
    public function openApi(): ResponseInterface
    {
        return $this->file(self::OPENAPI_FILE, 'OpenAPI');
    }

    /**
     * Apifox「定时导入」的数据源：数据源格式选 Apifox，URL 指向本接口。
     *
     * WebSocket 契约不进 OpenAPI（OpenAPI 表达不了长连接），走 Apifox 原生格式单独同步。
     */
    #[GetMapping(path: 'get_apifox')]
    #[PublicAccess]
    public function apifox(): ResponseInterface
    {
        return $this->file(self::APIFOX_FILE, 'Apifox');
    }

    private function file(string $relativePath, string $label): ResponseInterface
    {
        $path = BASE_PATH . $relativePath;
        if (! is_readable($path)) {
            return $this->response->json([
                'code' => 404,
                // Apifox 的产物由独立组件生成：只装 apidoc 时命令根本不存在，
                // 提示语必须把这一步一起说出来，否则会让人拿着一个不存在的命令去跑。
                'message' => $label === 'Apifox'
                    ? 'Apifox 文档尚未生成：需要 composer require hyperf-apex/apidoc-apifox 并运行 api-doc:generate-apifox。'
                    : 'OpenAPI 文档尚未生成，请先运行 api-doc:generate。',
                'data' => [],
            ])->withStatus(404);
        }

        $content = file_get_contents($path);
        if ($content === false) {
            return $this->response->json([
                'code' => 500,
                'message' => sprintf('%s 文档读取失败。', $label),
                'data' => [],
            ])->withStatus(500);
        }

        return $this->response->raw($content)->withHeader('content-type', 'application/json; charset=utf-8');
    }
}
