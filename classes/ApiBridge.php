<?php

declare(strict_types=1);

namespace Grav\Plugin\McpServer;

use FastRoute\DataGenerator\GroupCountBased;
use FastRoute\RouteCollector;
use FastRoute\RouteParser\Std;
use Grav\Common\Grav;
use Grav\Plugin\Api\ApiRouter;
use Nyholm\Psr7\ServerRequest;
use Nyholm\Psr7\Stream;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * In-process bridge to grav-plugin-api: builds a PSR-7 request and runs it
 * through the api plugin's own ApiRouter, so its entire pipeline (body parsing,
 * auth, scope cap, AdminProxy, audit, demo gate, rate limit, routing, RFC7807
 * mapping) executes exactly as it would over HTTP. We duplicate none of it.
 *
 * The caller's raw API key is forwarded as X-API-Key; the api plugin
 * re-authenticates and stamps api_user/api_key_scopes itself. Never stamp those
 * attributes here — that would uncap a scoped key.
 *
 * Not final: tests/param-map.php subclasses it with a recording stub that
 * captures each request() instead of dispatching it.
 */
class ApiBridge
{
    // ?Grav only so Grav-less test paths (smoke's site_info call) can construct
    // a bridge that never dispatches; request() requires a real Grav.
    public function __construct(private readonly ?Grav $grav, #[\SensitiveParameter] private readonly ?string $apiKey)
    {
    }

    /** The subset of $args under $keys, for tools that forward optional params verbatim. */
    public static function pick(array $args, array $keys): array
    {
        return array_intersect_key($args, array_flip($keys));
    }

    /** Path-encode one argument, keeping '/' separators (page routes, config scopes). */
    public static function path(array $args, string $key = 'route'): string
    {
        return str_replace('%2F', '/', rawurlencode(ltrim((string) ($args[$key] ?? ''), '/')));
    }

    /** Installed api plugin version from its blueprint, null if the plugin is absent. */
    public static function apiPluginVersion(Grav $grav): ?string
    {
        $file = $grav['locator']->findResource('plugins://api/blueprints.yaml');
        if (!\is_string($file) || !is_file($file)) {
            return null;
        }

        return preg_match('/^version:\s*(\S+)/m', (string) file_get_contents($file), $m) === 1 ? $m[1] : null;
    }

    /** The API base every route path hangs off, e.g. "/api/v1". */
    public function apiRoot(): string
    {
        $config = $this->grav === null ? null : $this->grav['config'];

        return '/' . trim((string) ($config?->get('plugins.api.route') ?? '/api'), '/')
            . '/' . ($config?->get('plugins.api.version_prefix') ?? 'v1');
    }

    /**
     * The live route table the api plugin's router would serve right now: core
     * routes plus everything third-party plugins registered.
     *
     * Recorded, not compiled — a RouteCollector subclass whose addRoute()
     * captures instead of generating dispatch data. Every alias (get/post/…),
     * addGroup(), and the ApiRouteCollector forwarder plugins are handed all
     * funnel through addRoute(), so nothing escapes the override.
     *
     * registerPluginRoutes() fires the real onApiRegisterRoutes event, which is
     * why this needs the live container rather than a source scan.
     *
     * Overridable: Grav-less tests stub it the way they stub request().
     *
     * @return list<array{method: string, path: string, handler: array}>
     */
    public function routes(): array
    {
        if ($this->grav === null) {
            throw new \RuntimeException('ApiBridge cannot enumerate routes without Grav.');
        }

        $collector = new class(new Std(), new GroupCountBased()) extends RouteCollector {
            /** @var list<array{method: string, path: string, handler: array}> */
            public array $recorded = [];

            public function addRoute(mixed $httpMethod, mixed $route, mixed $handler): void
            {
                foreach ((array) $httpMethod as $method) {
                    $this->recorded[] = [
                        'method' => strtoupper((string) $method),
                        'path' => $this->currentGroupPrefix . (string) $route,
                        'handler' => (array) $handler,
                    ];
                }
            }
        };

        $router = new ApiRouter($this->grav, $this->grav['config']);
        foreach (['registerCoreRoutes', 'registerPluginRoutes'] as $register) {
            (new \ReflectionMethod($router, $register))->invoke($router, $collector);
        }

        return $collector->recorded;
    }

    /**
     * @param array<string, mixed> $query scalars; bools become 'true'/'false', null/'' dropped
     * @param array<string, mixed>|null $body JSON body
     * @param array<string, string> $headers extra headers (If-Match, X-Config-Environment, ...)
     * @param array<string, mixed> $files PSR-7 UploadedFileInterface[] for multipart uploads
     * @return array{status:int, headers:array<string,string>, json:mixed, body:string}
     */
    public function request(string $method, string $path, array $query = [], ?array $body = null, array $headers = [], array $files = []): array
    {
        if ($this->grav === null) {
            throw new \RuntimeException('ApiBridge cannot dispatch without Grav.');
        }

        $config = $this->grav['config'];
        $apiBase = $this->apiRoot();

        $params = [];
        foreach ($query as $name => $value) {
            if ($value === null || $value === '') {
                continue;
            }
            $params[$name] = is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;
        }

        // Subpath-safe: ApiRouter::resolveApiRoutePath() peels the Grav base and
        // the api base back off the URI path to get the route it dispatches on.
        $uri = rtrim((string) $this->grav['uri']->rootUrl(false), '/') . $apiBase . $path;
        if ($params !== []) {
            $uri .= '?' . http_build_query($params);
        }

        // Multipart (uploads): fields ride getParsedBody(), not a JSON stream —
        // JsonBodyParserMiddleware only stamps json_body for application/json.
        if ($body !== null && $files === []) {
            $headers['Content-Type'] = 'application/json';
        }

        // The api plugin reads the caller's identity off the request we build:
        // AuditContext takes REMOTE_ADDR from the server params and the
        // User-Agent header, and per-IP rate limiting keys on REMOTE_ADDR too —
        // so forward the real $_SERVER and the caller's own User-Agent.
        if (!isset($headers['User-Agent']) && isset($_SERVER['HTTP_USER_AGENT'])) {
            $headers['User-Agent'] = (string) $_SERVER['HTTP_USER_AGENT'];
        }

        $request = (new ServerRequest($method, $uri, $headers, null, '1.1', $_SERVER))
            // Nyholm does not derive query params from the URI; controllers read
            // getQueryParams(), so set them explicitly.
            ->withQueryParams($params);

        if ($body !== null) {
            $request = $files === []
                ? $request->withBody(Stream::create((string) json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)))
                : $request->withParsedBody($body);
        }
        if ($files !== []) {
            $request = $request->withUploadedFiles($files);
        }
        // Last, deliberately: api_request forwards caller-supplied headers, and
        // stamping the key after them makes an override impossible rather than
        // merely denylisted.
        if ($this->apiKey !== null) {
            $request = $request->withHeader('X-API-Key', $this->apiKey);
        }

        $response = (new ApiRouter($this->grav, $config))->process($request, new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                throw new \RuntimeException('unreachable'); // ApiRouter::process() never delegates
            }
        });

        $responseHeaders = [];
        foreach (array_keys($response->getHeaders()) as $name) {
            $responseHeaders[strtolower((string) $name)] = $response->getHeaderLine((string) $name);
        }

        // Raw body too: api_request needs the bytes and the content type, not
        // just the JSON reading the curated tools take.
        $raw = (string) $response->getBody();

        return [
            'status' => $response->getStatusCode(),
            'headers' => $responseHeaders,
            'json' => json_decode($raw, true),
            'body' => $raw,
        ];
    }

    /** CallToolResult: compact JSON text block (whitespace is tokens). */
    public static function toolJson(mixed $data, bool $isError = false): array
    {
        return [
            'content' => [['type' => 'text', 'text' => json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)]],
            'isError' => $isError,
        ];
    }

    /** Error CallToolResult. */
    public static function toolError(string $message, ?string $hint = null): array
    {
        return [
            'content' => [['type' => 'text', 'text' => $hint === null ? $message : $message . "\n\nHint: " . $hint]],
            'isError' => true,
        ];
    }

    /**
     * Envelope → CallToolResult.
     *
     * @param array{status:int, headers:array<string,string>, json:mixed, body?:string} $resp
     * @param callable(mixed, mixed): mixed|null $transform
     * @param ?string $successMessage on any 2xx, reply {success, message} and ignore the body
     */
    public static function fromResponse(array $resp, bool $withEtag = false, ?callable $transform = null, ?string $successMessage = null): array
    {
        $json = $resp['json'];

        if ($resp['status'] >= 400) {
            return self::mapError($resp['status'], is_array($json) ? $json : []);
        }

        if ($successMessage !== null) {
            return self::toolJson(['success' => true, 'message' => $successMessage]);
        }

        if (!is_array($json) || !array_key_exists('data', $json)) {
            return self::toolJson(['success' => true]); // 204 / empty body
        }

        $data = $json['data'];

        if (isset($json['meta']['pagination'])) {
            $data = ['data' => $data, 'pagination' => $json['meta']['pagination']];
        }

        $etag = $resp['headers']['etag'] ?? '';
        if ($withEtag && $etag !== '' && is_array($data)) {
            $data['_etag'] = trim($etag, '"');
        }

        return self::toolJson($transform === null ? $data : $transform($data, $json));
    }

    /** RFC 7807 problem detail → friendly error message + hint. */
    private static function mapError(int $status, array $problem): array
    {
        $detail = (string) ($problem['detail'] ?? '');
        $title = (string) ($problem['title'] ?? '');

        return match (true) {
            $status === 401 => self::toolError('Authentication failed. Check your API key is valid and not expired.'),
            $status === 403 => self::toolError('Permission denied: ' . ($detail ?: $title), 'Check that your API key has the required permission.'),
            $status === 404 => self::toolError($detail ?: 'Resource not found.'),
            $status === 409 => self::toolError(
                'Resource was modified by another user since you last read it.',
                'Fetch the latest version first to get a current ETag, then retry your update.'
            ),
            $status === 422 => self::toolError('Validation error: ' . (self::fieldErrors($problem) ?: $detail ?: 'Invalid input')),
            $status === 429 => self::toolError('Rate limited. Try again later.', 'Wait for the rate limit window to reset before retrying.'),
            $status >= 500 => self::toolError(sprintf('Server error (%d): %s', $status, $detail ?: $title ?: 'Internal error')),
            default => self::toolError(sprintf('API error (%d): %s', $status, $detail ?: $title ?: 'Unknown error')),
        };
    }

    /** "field: message; field: message" from a ValidationException's errors[]. */
    private static function fieldErrors(array $problem): string
    {
        $errors = $problem['errors'] ?? null;

        return is_array($errors)
            ? implode('; ', array_map(
                static fn($e): string => is_array($e) ? ($e['field'] ?? '') . ': ' . ($e['message'] ?? '') : (string) $e,
                $errors
            ))
            : '';
    }
}
