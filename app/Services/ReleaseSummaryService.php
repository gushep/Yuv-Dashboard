<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;

class ReleaseSummaryService
{
    /** Seções da ficha de especificação usada pelo time nos comentários dos cards. */
    private const SPEC_SECTIONS = 'USER STORY|CONTEXTO|REGRAS DE NEGÓCIO|FLUXO OPERACIONAL|TELAS IMPACTADAS|PARÂMETROS|PERMISSÕES|ESTRUTURA DE DADOS|CRITÉRIOS DE ACEITE|ESCOPO|DEPENDÊNCIAS';

    /** Trecho do modelo de comentário que o time cola vazio nos cards. */
    private const EMPTY_TEMPLATE = 'Por que essa tarefa existe? Qual problema/dor resolve? ...';

    /**
     * OBJETIVO da ficha de especificação, quando o card tem uma ficha preenchida.
     *
     * @param  list<array<string, mixed>>  $comments
     */
    public function objective(array $comments): ?string
    {
        foreach ($this->texts($comments) as $text) {
            if (! preg_match('/\bOBJETIVO\b:?\s*(.+?)(?=\s+(?:'.self::SPEC_SECTIONS.')\b|$)/u', $text, $matches)) {
                continue;
            }
            $objective = trim($matches[1]);
            if (mb_strlen($objective) > 400) {
                // Corta no fim da última frase que cabe; sem frase completa, na última palavra.
                $cut = mb_substr($objective, 0, 400);
                $end = mb_strrpos($cut, '. ');
                $objective = $end !== false ? mb_substr($cut, 0, $end + 1) : rtrim(mb_substr($cut, 0, (int) mb_strrpos($cut, ' ')), ' ,;').'…';
            }
            if (mb_strlen($objective) >= 20 && ! str_contains($objective, '...') && ! preg_match('/https?:|@\S+\.\S+/iu', $objective)) {
                return $objective;
            }
        }

        return null;
    }

    /**
     * Texto limpo do card (título e comentários, sem o modelo vazio) para resumir fora do dashboard.
     *
     * @param  list<array<string, mixed>>  $comments
     */
    public function sourceText(string $title, array $comments): string
    {
        $texts = array_filter($this->texts($comments), fn (string $text): bool => ! str_contains($text, self::EMPTY_TEMPLATE));

        return trim("Título: {$title}\n\n".implode("\n---\n", $texts));
    }

    /** Assinatura do conteúdo do card: muda quando título ou comentários mudam, tornando o resumo desatualizado. */
    public function sourceHash(string $title, array $comments): string
    {
        return hash('sha256', $this->sourceText($title, $comments));
    }

    /**
     * Textos dos comentários e respostas, sem HTML e com espaços normalizados.
     *
     * @param  list<array<string, mixed>>  $comments
     * @return list<string>
     */
    private function texts(array $comments): array
    {
        $texts = [];
        foreach ($comments as $comment) {
            foreach ([(string) ($comment['text_body'] ?? $comment['body'] ?? ''), ...array_map(fn (array $reply): string => (string) ($reply['body'] ?? ''), $comment['replies'] ?? [])] as $source) {
                $text = trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($source), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
                if ($text !== '') {
                    $texts[] = $text;
                }
            }
        }

        return $texts;
    }

    public function summarize(string $itemId, array $comments, array $names = []): ?string
    {
        $sourceHash = hash('sha256', json_encode([$comments, $names], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        $cached = Cache::remember('release-summary-v1-'.$itemId.'-'.$sourceHash, 86400,
            fn (): array => ['description' => $this->extract($comments, $names)]);

        return $cached['description'];
    }

    private function extract(array $comments, array $names): ?string
    {
        $texts = [];
        foreach ($comments as $comment) {
            $texts[] = (string) ($comment['text_body'] ?? '');
            $names[] = (string) ($comment['creator']['name'] ?? '');
            foreach ($comment['replies'] ?? [] as $reply) {
                $texts[] = (string) ($reply['body'] ?? '');
                $names[] = (string) ($reply['creator']['name'] ?? '');
            }
        }
        $candidates = [];
        foreach ($texts as $source) {
            $text = trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($source), ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?? '');
            if (preg_match('/não (?:funciona|foi|está|resolve|corrig)|continua|reabert|revert|contradi|ainda falta/iu', $text)) {
                return null;
            }
            if (preg_match('/hipótes|talvez|descart|pendente|estimativ|aguard/iu', $text)) {
                continue;
            }
            $text = preg_replace('/^(?:solução final|ajuste realizado|correção realizada):\s*/iu', '', $text) ?? '';
            if (mb_strlen($text) < 30 || mb_strlen($text) > 280
                || ! preg_match('/^(?:Corrigid[oa]s?|Ajustad[oa]s?|Adicionad[oa]s?|Implementad[oa]s?|Otimizad[oa]s?|Removid[oa]s?|Habilitad[oa]s?|Atualizad[oa]s?)\s/iu', $text)) {
                continue;
            }
            if (preg_match('/[\d@<>\[\]{}"=\/\\\\]|https?|senha|token|credencia|segredo|secret|api.key|cliente|usuário|usuario|pessoa|paciente|cpf|cnpj|telefone|e.mail|endereço|endereco|branch|commit|deploy|sql|banco de dados|produção|producao|homologad|publicad|validado|ignore|instruç|prompt|estimativ|aguard|cobran|precisa|dever|será|poder|talvez|por\s+\p{L}+/iu', $text)) {
                continue;
            }
            $rest = preg_replace('/^\S+\s+/u', '', $text) ?? '';
            if (preg_match('/\b\p{Lu}/u', $rest) || substr_count(rtrim($text, '.'), '.') > 0 || str_contains($text, '?') || str_contains($text, '!')) {
                continue;
            }
            foreach ($names as $name) {
                foreach (preg_split('/[\s,]+/u', trim($name)) ?: [] as $part) {
                    if (mb_strlen($part) >= 3 && preg_match('/\b'.preg_quote($part, '/').'\b/iu', $text)) {
                        continue 3;
                    }
                }
            }
            $candidates[] = $text;
        }
        $candidates = array_values(array_unique($candidates));

        return count($candidates) === 1 ? $candidates[0] : null;
    }
}
