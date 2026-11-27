<?php

namespace Iquesters\Notifications\Support;

use Illuminate\Support\Str;

/**
 * Fills a template: {{name}} placeholders with values (escaped in HTML), the
 * body's light formatting (Markdown: **bold**, lists, [links](…)) as HTML. No
 * Blade: a template can't run code. Unknown placeholders are left out.
 */
class Renderer
{
    public static function text(?string $template, array $values): string
    {
        return trim(preg_replace_callback('/\{\{\s*([a-z0-9_.]+)\s*\}\}/i', fn ($m) => (string) ($values[$m[1]] ?? ''), (string) $template));
    }

    public static function html(?string $template, array $values): string
    {
        $tokens = [];
        // Placeholders become tokens first, so a value is never read as formatting.
        $marked = preg_replace_callback('/\{\{\s*([a-z0-9_.]+)\s*\}\}/i', function ($m) use (&$tokens) {
            $token = 'NTFTOKEN'.count($tokens).'X';
            $tokens[$token] = $m[1];

            return $token;
        }, (string) $template);
        $html = Str::markdown($marked, ['html_input' => 'strip', 'allow_unsafe_links' => false]);
        foreach ($tokens as $token => $name) {
            $html = str_replace($token, e((string) ($values[$name] ?? '')), $html);
        }

        return $html;
    }
}
