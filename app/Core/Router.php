<?php

declare(strict_types=1);

namespace FindSync\Core;

use FindSync\Middleware\AuthMiddleware;
use FindSync\Middleware\CsrfMiddleware;
use FindSync\Middleware\RoleMiddleware;

final class Router
{
    /** @var array<int, array<string, mixed>> */
    private array $routes = [];

    /**
     * @param array<string, mixed> $options
     */
    public function add(string $method, string $pattern, string $controller, string $action, array $options = []): self
    {
        $this->routes[] = [
            'method' => strtoupper($method),
            'pattern' => $pattern,
            'controller' => $controller,
            'action' => $action,
            'options' => $options,
        ];
        return $this;
    }

    public function dispatch(): never
    {
        $route = Request::route();
        $method = Request::method();

        foreach ($this->routes as $definition) {
            if ($definition['method'] !== $method) {
                continue;
            }

            $regex = '#^' . preg_replace('#\{([A-Za-z_][A-Za-z0-9_]*)\}#', '(?P<$1>[^/]+)', $definition['pattern']) . '$#';
            if (!preg_match($regex, $route, $matches)) {
                continue;
            }

            $params = [];
            foreach ($matches as $key => $value) {
                if (is_string($key)) {
                    $params[$key] = $value;
                }
            }

            /** @var array<string, mixed> $options */
            $options = $definition['options'];

            if (($options['auth'] ?? false) === true) {
                AuthMiddleware::requireUser();
            }
            if (isset($options['roles']) && is_array($options['roles'])) {
                RoleMiddleware::requireRoles($options['roles']);
            }
            if (Request::isWrite() && ($options['csrf'] ?? true) === true) {
                CsrfMiddleware::validate(Request::csrfToken());
            }

            $controllerClass = $definition['controller'];
            $action = $definition['action'];
            $controller = new $controllerClass();
            $controller->{$action}($params);
        }

        throw new NotFoundException('API endpoint not found.');
    }
}
