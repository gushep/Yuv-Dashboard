<?php

namespace App\Mcp\Support;

/**
 * Converte o escopo em Markdown para o HTML aceito nos comentários do Monday: h1, h2, p, strong, ul/ol/li.
 * Títulos ### e abaixo viram parágrafo em negrito, tabelas viram lista ("coluna: valor") e critérios de aceite
 * "- [ ]" viram "☐", porque o editor do Monday não recebe checkbox pela API. Todo texto é escapado.
 */
final class ScopeMarkdown
{
    public static function toHtml(string $markdown): string
    {
        $html = [];
        $list = null;
        $header = null;
        $close = function () use (&$html, &$list): void {
            if ($list !== null) {
                $html[] = "</{$list}>";
                $list = null;
            }
        };
        $open = function (string $type) use (&$html, &$list, $close): void {
            if ($list !== $type) {
                $close();
                $html[] = "<{$type}>";
                $list = $type;
            }
        };

        foreach (preg_split('/\R/u', $markdown) as $line) {
            $text = trim($line);
            if ($text === '' || preg_match('/^(-{3,}|\*{3,})$/', $text)) {
                $close();
                $header = null;

                continue;
            }
            if (str_starts_with($text, '|')) {
                $cells = array_map('trim', explode('|', trim($text, '|')));
                if (preg_match('/^\|?[\s:|-]+\|?$/', $text)) {
                    continue;
                }
                if ($header === null) {
                    $header = $cells;

                    continue;
                }
                $open('ul');
                $parts = [];
                foreach ($cells as $index => $cell) {
                    $parts[] = $index === 0 ? '<strong>'.self::inline($cell).'</strong>' : self::inline(($header[$index] ?? '').': '.$cell);
                }
                $html[] = '<li>'.implode(' — ', $parts).'</li>';

                continue;
            }
            $header = null;
            if (preg_match('/^(#{1,6})\s+(.+)$/u', $text, $match)) {
                $close();
                $level = strlen($match[1]);
                $html[] = match ($level) {
                    1 => '<h1>'.self::inline($match[2]).'</h1>',
                    2 => '<h2>'.self::inline($match[2]).'</h2>',
                    default => '<p><strong>'.self::inline($match[2]).'</strong></p>',
                };

                continue;
            }
            if (preg_match('/^[-*]\s+\[[ xX]?\]\s*(.+)$/u', $text, $match)) {
                $open('ul');
                $html[] = '<li>☐ '.self::inline($match[1]).'</li>';

                continue;
            }
            if (preg_match('/^[-*]\s+(.+)$/u', $text, $match)) {
                $open('ul');
                $html[] = '<li>'.self::inline($match[1]).'</li>';

                continue;
            }
            if (preg_match('/^\d+[.)]\s+(.+)$/u', $text, $match)) {
                $open('ol');
                $html[] = '<li>'.self::inline($match[1]).'</li>';

                continue;
            }
            $close();
            $html[] = '<p>'.self::inline(ltrim($text, '> ')).'</p>';
        }
        $close();

        return implode('', $html);
    }

    /**
     * Texto de uma linha: escapado, com **negrito** e sem os acentos graves de código.
     */
    private static function inline(string $text): string
    {
        $escaped = htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $escaped = preg_replace('/\*\*(.+?)\*\*/u', '<strong>$1</strong>', $escaped);

        return str_replace('`', '', (string) $escaped);
    }
}
