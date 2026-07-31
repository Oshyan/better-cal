<?php

declare(strict_types=1);

namespace BetterCal\Http;

final class Router
{
    /** @var array<string, list<array{pattern:string, regex:string, names:list<string>, handler:callable}>> */
    private array $routes = [];

    public function add(string $method, string $pattern, callable $handler): void
    {
        $names = [];
        $regex = preg_replace_callback('/:([A-Za-z_][A-Za-z0-9_]*)/', function ($m) use (&$names) {
            $names[] = $m[1];
            return '([^/]+)';
        }, $pattern);
        $this->routes[strtoupper($method)][] = [
            'pattern' => $pattern,
            'regex' => '#^' . $regex . '$#',
            'names' => $names,
            'handler' => $handler,
        ];
    }

    /** @return array{handler:callable, params:array<string,string>} */
    public function match(string $method, string $path): array
    {
        $method = strtoupper($method);
        if ($method === 'HEAD') {
            $method = 'GET';
        }
        foreach ($this->routes[$method] ?? [] as $route) {
            if (preg_match($route['regex'], $path, $m)) {
                $params = [];
                foreach ($route['names'] as $i => $name) {
                    $params[$name] = rawurldecode($m[$i + 1]);
                }
                return ['handler' => $route['handler'], 'params' => $params];
            }
        }
        foreach ($this->routes as $otherMethod => $routes) {
            if ($otherMethod === $method) {
                continue;
            }
            foreach ($routes as $route) {
                if (preg_match($route['regex'], $path)) {
                    throw new HttpError('method_not_allowed', 'Method not allowed', 405);
                }
            }
        }
        throw HttpError::notFound('No such endpoint');
    }
}
