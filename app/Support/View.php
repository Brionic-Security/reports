<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Tiny PHP-template view renderer with layout inheritance.
 */
final class View
{
    private string $viewsPath;
    private array $data = [];
    private array $sections = [];
    private array $stack = [];
    private ?string $layoutTemplate = null;
    private array $layoutData = [];

    private function __construct()
    {
        $this->viewsPath = resource_path('views');
    }

    public static function render(string $template, array $data = []): string
    {
        return (new self())->renderTemplate($template, $data);
    }

    public static function exists(string $template): bool
    {
        return is_file(resource_path('views/' . $template . '.php'));
    }

    private function renderTemplate(string $template, array $data): string
    {
        $this->data = array_merge($this->data, $data);
        $content = $this->capture($template, $this->data);

        if ($this->layoutTemplate !== null) {
            $layout = $this->layoutTemplate;
            $this->layoutTemplate = null;
            $this->sections['content'] ??= $content;
            $content = $this->capture($layout, array_merge($this->data, $this->layoutData));
        }

        return $content;
    }

    private function capture(string $template, array $data): string
    {
        $file = $this->viewsPath . '/' . $template . '.php';
        if (!is_file($file)) {
            throw new \RuntimeException("View not found: {$template}");
        }
        extract($data, EXTR_SKIP);
        ob_start();
        include $file;
        return (string) ob_get_clean();
    }

    public function layout(string $template, array $data = []): void
    {
        $this->layoutTemplate = $template;
        $this->layoutData = $data;
    }

    public function start(string $name): void
    {
        $this->stack[] = $name;
        ob_start();
    }

    public function stop(): void
    {
        $name = array_pop($this->stack);
        $this->sections[$name] = (string) ob_get_clean();
    }

    public function section(string $name, string $default = ''): string
    {
        return $this->sections[$name] ?? $default;
    }

    public function include(string $template, array $data = []): void
    {
        echo $this->capture($template, array_merge($this->data, $data));
    }

    public function e(mixed $value): string
    {
        return e($value);
    }
}
