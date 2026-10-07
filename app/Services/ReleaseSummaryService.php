<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;

class ReleaseSummaryService
{
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
