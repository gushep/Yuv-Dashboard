<?php

namespace App\Support;

use Illuminate\Support\Facades\File;

/**
 * Gera o public/dark-theme.css a partir dos estilos do dashboard (o <style> da página e os .css carregados por ela).
 *
 * Cada regra que define cor é repetida com o prefixo [data-theme=dark], então todas ganham a mesma especificidade a mais
 * e continuam valendo na mesma ordem do tema claro. As cores mudam de luminosidade (claro vira escuro e vice-versa) e
 * mantêm o tom; as variáveis principais do :root têm valores escolhidos à mão, em TOKENS.
 */
class DarkThemeStylesheet
{
    public const OUTPUT = 'dark-theme.css';

    /**
     * Variáveis do :root no tema escuro.
     *
     * @var array<string, string>
     */
    public const TOKENS = [
        '--blue' => '#4d8ef7', '--blue-lt' => '#6ea3f9', '--blue-bg' => '#16243d', '--blue-bg2' => '#1f3459',
        '--green' => '#3ccf74', '--green-bg' => '#10261a', '--green-bd' => '#1f4a2f',
        '--red' => '#f26b6b', '--red-bg' => '#2c1618', '--red-bd' => '#5c2528',
        '--yellow' => '#f2a93b', '--yel-bg' => '#2b2112', '--yel-bd' => '#5c4519',
        '--purple' => '#a585f8', '--teal' => '#2fc2b3',
        '--bg' => '#0e131b', '--sidebar' => '#151b25', '--card' => '#161c26', '--border' => '#283243',
        '--text' => '#e6ebf2', '--text2' => '#aab4c3', '--muted' => '#7d8898',
        '--shadow' => '0 1px 8px rgba(0,0,0,.35)', '--shadow-md' => '0 4px 20px rgba(0,0,0,.55)',
    ];

    /**
     * Ajustes que a troca automática não resolve (ex.: tooltip que usa a cor do texto como fundo).
     */
    public const EXTRA = <<<'CSS'
:root[data-theme=dark]{color-scheme:dark}
[data-theme=dark] .kpi-tip{background:#2a3446;color:#e6ebf2}
CSS;

    private const COLOR_PROPERTY = '/^(color|background(-color|-image)?|border(-(top|right|bottom|left|block|inline)(-(start|end))?)?(-color)?|outline(-color)?|box-shadow|text-shadow|fill|stroke|accent-color|caret-color|text-decoration(-color)?|column-rule(-color)?|scrollbar-color)$/';

    private const COLOR_TOKEN = '/#[0-9a-fA-F]{3,8}\b|rgba?\([^)]*\)|var\(--[\w-]+\)|color-mix\((?:[^()]|\([^()]*\))*\)|\b(?:transparent|currentcolor|white|black)\b/i';

    /** Pontos da curva de luminosidade (OKLab): [claro, escuro]. */
    private const LIGHTNESS_CURVE = [[0, .98], [.15, .94], [.3, .87], [.45, .78], [.6, .70], [.72, .62], [.78, .48], [.86, .33], [.93, .26], [1, .21]];

    public function generate(): string
    {
        $css = '';
        foreach ($this->sources() as $source) {
            $css .= $this->render($this->parse($source))."\n";
        }

        $header = "/* Tema escuro. Gerado por \"php artisan theme:dark\" a partir dos estilos do dashboard: não edite à mão. */\n";

        return $header.trim(preg_replace("/\n{2,}/", "\n", $css))."\n".self::EXTRA."\n";
    }

    public function write(): string
    {
        $path = public_path(self::OUTPUT);
        File::put($path, $this->generate());

        return $path;
    }

    /**
     * O <style> da página e os .css na ordem em que ela os carrega.
     *
     * @return list<string>
     */
    private function sources(): array
    {
        $page = str_replace("\r\n", "\n", File::get(resource_path('views/dashboard.blade.php')));
        preg_match('~<style>(.*?)</style>~s', $page, $style);
        $sources = [$style[1] ?? ''];
        preg_match_all("~URL::versioned\('([\w-]+\.css)'\)~", $page, $links);
        foreach ($links[1] as $file) {
            if ($file !== self::OUTPUT) {
                $sources[] = str_replace("\r\n", "\n", File::get(public_path($file)));
            }
        }

        return $sources;
    }

    /**
     * Regras e blocos @media/@supports; @keyframes, @font-face e afins ficam de fora.
     *
     * @return list<array{at?: string, children?: list<array<string, mixed>>, selector?: string, declarations?: list<array{0: string, 1: string}>}>
     */
    private function parse(string $css): array
    {
        $css = preg_replace('~/\*.*?\*/~s', '', $css);
        $nodes = [];
        $length = strlen($css);
        $index = 0;
        while ($index < $length) {
            $start = $index;
            $index = $this->scanTo($css, $index, ['{', ';', '}']);
            if ($index >= $length) {
                break;
            }
            $prelude = trim(substr($css, $start, $index - $start));
            if ($css[$index] !== '{') {
                $index++;

                continue;
            }
            $bodyStart = $index + 1;
            $depth = 1;
            for ($index = $bodyStart; $index < $length && $depth > 0; $index++) {
                $depth += match ($css[$index]) {
                    '{' => 1,
                    '}' => -1,
                    default => 0,
                };
            }
            $body = substr($css, $bodyStart, $index - 1 - $bodyStart);
            if (str_starts_with($prelude, '@')) {
                if (preg_match('/^@(media|supports|container)\b/i', $prelude)) {
                    $nodes[] = ['at' => $prelude, 'children' => $this->parse($body)];
                }

                continue;
            }
            $nodes[] = ['selector' => $prelude, 'declarations' => $this->declarations($body)];
        }

        return $nodes;
    }

    /**
     * Posição do próximo delimitador fora de parênteses e aspas.
     *
     * @param  list<string>  $stops
     */
    private function scanTo(string $css, int $index, array $stops): int
    {
        $length = strlen($css);
        $parens = 0;
        $quote = null;
        for (; $index < $length; $index++) {
            $char = $css[$index];
            if ($quote !== null) {
                if ($char === '\\') {
                    $index++;
                } elseif ($char === $quote) {
                    $quote = null;
                }

                continue;
            }
            if ($char === '"' || $char === "'") {
                $quote = $char;
            } elseif ($char === '(') {
                $parens++;
            } elseif ($char === ')') {
                $parens--;
            } elseif ($parens === 0 && in_array($char, $stops, true)) {
                return $index;
            }
        }

        return $index;
    }

    /**
     * @return list<array{0: string, 1: string}>
     */
    private function declarations(string $body): array
    {
        $declarations = [];
        $index = 0;
        $length = strlen($body);
        while ($index < $length) {
            $end = $this->scanTo($body, $index, [';']);
            $declaration = trim(substr($body, $index, $end - $index));
            $index = $end + 1;
            $colon = strpos($declaration, ':');
            if ($colon !== false) {
                $declarations[] = [strtolower(trim(substr($declaration, 0, $colon))), trim(substr($declaration, $colon + 1))];
            }
        }

        return $declarations;
    }

    /**
     * @param  list<array<string, mixed>>  $nodes
     */
    private function render(array $nodes): string
    {
        $out = '';
        foreach ($nodes as $node) {
            if (isset($node['at'])) {
                $inner = $this->render($node['children']);
                if ($inner !== '') {
                    $out .= "{$node['at']}{\n{$inner}}\n";
                }

                continue;
            }
            $isRoot = trim($node['selector']) === ':root';
            $declarations = [];
            foreach ($node['declarations'] as [$property, $value]) {
                foreach ($this->darkDeclarations($property, $value, $isRoot) as $declaration) {
                    $declarations[] = $declaration;
                }
            }
            if ($declarations !== []) {
                $out .= $this->prefix($node['selector']).'{'.implode(';', $declarations)."}\n";
            }
        }

        return $out;
    }

    /**
     * Declarações de cor no tema escuro. Os atalhos (border, outline, background) viram só a parte da cor, para não
     * desfazer larguras e estilos definidos por regras mais específicas.
     *
     * @return list<string>
     */
    private function darkDeclarations(string $property, string $value, bool $isRoot): array
    {
        if (str_starts_with($property, '--')) {
            if ($isRoot && array_key_exists($property, self::TOKENS)) {
                return ["{$property}:".self::TOKENS[$property]];
            }

            return preg_match(self::COLOR_TOKEN, $value) ? ["{$property}:".$this->transform($value, 'text')] : [];
        }
        if (! preg_match(self::COLOR_PROPERTY, $property)) {
            return [];
        }
        [$value, $important] = $this->splitImportant($value);
        $role = in_array($property, ['color', 'fill', 'stroke', 'caret-color', 'text-decoration-color', 'text-decoration'], true) ? 'text' : 'surface';

        if ($property === 'background') {
            if (preg_match('/\b(none)\b/i', $value) && ! preg_match(self::COLOR_TOKEN, $value)) {
                return ["background-color:transparent{$important}", "background-image:none{$important}"];
            }
            if (preg_match('/gradient\(|url\(/i', $value)) {
                return ['background-image:'.$this->transform($value, 'surface').$important];
            }

            return ['background-color:'.$this->transform($value, 'surface').$important];
        }

        if (preg_match('/^(border(-(top|right|bottom|left|block|inline)(-(start|end))?)?|outline|text-decoration|column-rule)$/', $property)) {
            if (! preg_match(self::COLOR_TOKEN, $value, $match)) {
                return [];
            }

            return ["{$property}-color:".$this->transform($match[0], $role).$important];
        }

        return ["{$property}:".$this->transform($value, $role).$important];
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function splitImportant(string $value): array
    {
        if (preg_match('/\s*!important\s*$/i', $value, $match)) {
            return [substr($value, 0, -strlen($match[0])), '!important'];
        }

        return [$value, ''];
    }

    /** Prefixo [data-theme=dark] em cada seletor da lista. */
    private function prefix(string $selector): string
    {
        $parts = [];
        $index = 0;
        $length = strlen($selector);
        while ($index <= $length) {
            $end = $this->scanTo($selector, $index, [',']);
            $part = trim(substr($selector, $index, $end - $index));
            $index = $end + 1;
            if ($part === '') {
                continue;
            }
            $parts[] = match (true) {
                str_starts_with($part, ':root') => ':root[data-theme=dark]'.substr($part, 5),
                str_starts_with($part, 'html') => 'html[data-theme=dark]'.substr($part, 4),
                default => '[data-theme=dark] '.$part,
            };
        }

        return implode(',', $parts);
    }

    /** Troca as cores literais do valor; variáveis já respondem ao tema pelo :root. */
    private function transform(string $value, string $role): string
    {
        return preg_replace_callback('/#[0-9a-fA-F]{3,8}\b|rgba?\([^)]*\)|\b(white|black)\b/i', function (array $match) use ($role): string {
            $rgba = $this->toRgba($match[0]);
            if ($rgba === null) {
                return $match[0];
            }
            [$red, $green, $blue, $alpha] = $rgba;
            $isBlack = $red === 0 && $green === 0 && $blue === 0;
            $isWhite = $red === 255 && $green === 255 && $blue === 255;
            // Sombras e véus pretos continuam iguais; branco translúcido e texto branco ficam sobre cor forte, que não muda.
            if (($isBlack && $alpha < 1) || ($isWhite && ($alpha < 1 || $role === 'text'))) {
                return $match[0];
            }

            return $this->format($this->darken([$red, $green, $blue], $role), $alpha);
        }, $value);
    }

    /**
     * @return array{0: int, 1: int, 2: int, 3: float}|null
     */
    private function toRgba(string $color): ?array
    {
        $color = strtolower($color);
        if ($color === 'white') {
            return [255, 255, 255, 1.0];
        }
        if ($color === 'black') {
            return [0, 0, 0, 1.0];
        }
        if ($color[0] === '#') {
            $hex = substr($color, 1);
            if (strlen($hex) === 3 || strlen($hex) === 4) {
                $hex = implode('', array_map(fn (string $char): string => $char.$char, str_split($hex)));
            }
            if (! in_array(strlen($hex), [6, 8], true)) {
                return null;
            }

            return [hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2)), strlen($hex) === 8 ? round(hexdec(substr($hex, 6, 2)) / 255, 3) : 1.0];
        }
        if (! preg_match('/^rgba?\(\s*(\d+)[\s,]+(\d+)[\s,]+(\d+)(?:[\s,\/]+([\d.]+%?))?\s*\)$/', $color, $parts)) {
            return null;
        }
        $alpha = isset($parts[4]) ? (str_ends_with($parts[4], '%') ? (float) $parts[4] / 100 : (float) $parts[4]) : 1.0;

        return [(int) $parts[1], (int) $parts[2], (int) $parts[3], $alpha];
    }

    /**
     * Mesmo tom com a luminosidade invertida pela curva. Fundos de cor forte (botões, selos) mantêm a luminosidade.
     *
     * @param  array{0: int, 1: int, 2: int}  $rgb
     * @return array{0: int, 1: int, 2: int}
     */
    private function darken(array $rgb, string $role): array
    {
        [$lightness, $a, $b] = $this->toOklab($rgb);
        $chroma = sqrt($a * $a + $b * $b);
        $hue = atan2($b, $a);
        $isStrongSurface = $role === 'surface' && $chroma > .08 && $lightness > .35 && $lightness < .8;
        $newLightness = $isStrongSurface ? $lightness : $this->curve($lightness);
        // Tons claros que viram fundo escuro perdem parte da saturação, para não ficarem berrantes.
        if ($lightness > .8 && $newLightness < .5) {
            $chroma = min($chroma, .05);
        }
        for ($attempt = 0; $attempt < 40; $attempt++) {
            $candidate = $this->fromOklab([$newLightness, $chroma * cos($hue), $chroma * sin($hue)]);
            if ($candidate !== null) {
                return $candidate;
            }
            $chroma *= .9;
        }

        return $this->fromOklab([$newLightness, 0, 0]) ?? [0, 0, 0];
    }

    private function curve(float $lightness): float
    {
        $points = self::LIGHTNESS_CURVE;
        for ($index = 1; $index < count($points); $index++) {
            [$x1, $y1] = $points[$index];
            [$x0, $y0] = $points[$index - 1];
            if ($lightness <= $x1) {
                return $y0 + ($y1 - $y0) * ($lightness - $x0) / ($x1 - $x0);
            }
        }

        return end($points)[1];
    }

    /**
     * @param  array{0: int, 1: int, 2: int}  $rgb
     * @return array{0: float, 1: float, 2: float}
     */
    private function toOklab(array $rgb): array
    {
        [$red, $green, $blue] = array_map(function (int $channel): float {
            $value = $channel / 255;

            return $value <= .04045 ? $value / 12.92 : (($value + .055) / 1.055) ** 2.4;
        }, $rgb);
        $l = (.4122214708 * $red + .5363325363 * $green + .0514459929 * $blue) ** (1 / 3);
        $m = (.2119034982 * $red + .6806995451 * $green + .1073969566 * $blue) ** (1 / 3);
        $s = (.0883024619 * $red + .2817188376 * $green + .6299787005 * $blue) ** (1 / 3);

        return [
            .2104542553 * $l + .7936177850 * $m - .0040720468 * $s,
            1.9779984951 * $l - 2.4285922050 * $m + .4505937099 * $s,
            .0259040371 * $l + .7827717662 * $m - .8086757660 * $s,
        ];
    }

    /**
     * Cor sRGB ou null quando fica fora do gamut.
     *
     * @param  array{0: float, 1: float, 2: float}  $lab
     * @return array{0: int, 1: int, 2: int}|null
     */
    private function fromOklab(array $lab): ?array
    {
        [$lightness, $a, $b] = $lab;
        $l = ($lightness + .3963377774 * $a + .2158037573 * $b) ** 3;
        $m = ($lightness - .1055613458 * $a - .0638541728 * $b) ** 3;
        $s = ($lightness - .0894841775 * $a - 1.2914855480 * $b) ** 3;
        $linear = [
            4.0767416621 * $l - 3.3077115913 * $m + .2309699292 * $s,
            -1.2684380046 * $l + 2.6097574011 * $m - .3413193965 * $s,
            -.0041960863 * $l - .7034186147 * $m + 1.7076147010 * $s,
        ];
        $rgb = [];
        foreach ($linear as $value) {
            if ($value < -.001 || $value > 1.001) {
                return null;
            }
            $value = max(0, min(1, $value));
            $encoded = $value <= .0031308 ? 12.92 * $value : 1.055 * $value ** (1 / 2.4) - .055;
            $rgb[] = (int) round($encoded * 255);
        }

        return $rgb;
    }

    /**
     * @param  array{0: int, 1: int, 2: int}  $rgb
     */
    private function format(array $rgb, float $alpha): string
    {
        if ($alpha >= 1) {
            return sprintf('#%02x%02x%02x', ...$rgb);
        }

        return sprintf('rgba(%d,%d,%d,%s)', $rgb[0], $rgb[1], $rgb[2], rtrim(rtrim(number_format($alpha, 3, '.', ''), '0'), '.') ?: '0');
    }
}
