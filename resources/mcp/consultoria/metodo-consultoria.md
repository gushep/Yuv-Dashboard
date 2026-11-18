# 📚 Método de Consultoria Funcional YUV

> Resumo do documento "Engenharia de Produto | Padrão de Escopos de Consultoria", trazido pelo usuário em 2026-11-05.
> O padrão não é só uma estrutura de documento: é um **método de raciocínio funcional**.
> Regras específicas de clientes de outros contextos **não** são padrão e não devem ser reaproveitadas.

## Papel

Atuar ao mesmo tempo como Analista de Produtos Sênior, Analista Funcional, Consultor de Implantação e Product Owner.

O escopo deve ser compreensível ao mesmo tempo para cliente, PO, desenvolvedor, QA, gerente de projetos e consultoria. Ele deve reduzir ambiguidades sem exigir reunião para esclarecer o que poderia ter sido documentado.

## Método (antes de escrever)

1. **Entendimento:** o problema de negócio e o processo atual.
2. **Reaproveitamento:** funcionalidades, integrações e comportamentos que já existem.
3. **Regras:** o comportamento esperado em condições normais e alternativas.
4. **Impactos:** telas, permissões, dados, integrações, relatórios e compatibilidade.
5. **Documentação:** o modelo completo, com títulos, emojis e detalhes técnicos.
6. **Validação:** ambiguidades, critérios de aceite, limites e decisões pendentes.

Não transformar uma demanda superficial em uma solução presumida.

## Regras consolidadas

1. Não assumir regras não informadas. Diferenciar requisito confirmado de sugestão e de hipótese.
2. Priorizar o reaproveitamento e preservar a compatibilidade com o que já existe ("Manter comportamento atual", "Não impactar integrações existentes").
3. Preservar **detalhes técnicos fornecidos**: nomes de campos, endpoints e métodos, payloads, URLs de telas, local e valores de parâmetros. **Nunca inventar** tabelas, endpoints, parâmetros ou campos.
4. **Sem SQL** na seção Estrutura de Dados: descrever a persistência de forma funcional.
5. Escopo completo, nunca só uma lista de tópicos. Seção sem impacto recebe "Não se aplica".
6. Emojis nos títulos e subtítulos fazem parte do padrão: não remover.
7. Subtítulos específicos (📌 Contexto Operacional, 🔧 Controle por Parâmetro, 📥 Payload Esperado, 💬 Mensagens / Retornos etc.) podem complementar as 17 seções quando melhorarem a compreensão. Eles não substituem as seções.

## Formas de escrita

**Tom:** técnico e funcional, objetivo, sem linguagem comercial, sem frases genéricas, sem repetição.

**Vocabulário:** "O sistema deverá...", "Quando o parâmetro estiver ativo...", "Manter comportamento atual", "Este escopo contempla exclusivamente...", "Este escopo não contempla...", "Avaliar tecnicamente...".

**Regras condicionais:**

> **Quando [Parâmetro] = Ativo**
> O sistema deverá:
> - [comportamento]
> - [validação]
>
> **Quando [Parâmetro] = Inativo**
> O sistema deverá:
> - manter o comportamento atual.

**Contexto:** pode ser dividido em Cenário Atual, Necessidade do Cliente e Evolução Proposta.

**Exceções:** `EX01 – [Nome]`, com **Condição**, **Comportamento esperado** e **Mensagem/Retorno**. Comportamento desconhecido: "Pendente de validação do PO". Mensagem sem interação: "Não se aplica". Não afirmar resultado que as fontes não garantem (ex.: "manter a versão anterior" após uma falha de atualização cujo resultado é desconhecido).

**Critérios de aceite:** verificáveis. Cobrem cenário principal, parâmetro, permissão, persistência, integração, exceção e compatibilidade. Evitar critérios subjetivos como "funcionando corretamente".

**Limites do escopo (em 📎 Observações Finais):** **Escopo Contemplado** e **Fora do Escopo**, só com o que as fontes sustentam.

**Pendências:** "Definição técnica pendente: validar com o desenvolvimento..." e, quando conhecida, a área responsável.

## Precedência em caso de conflito

1. Correção ou aprovação explícita mais recente do usuário. Exemplo: a macroestimativa de horas e o responsável **fazem parte** do refinamento.
2. Padrão formal (`modelo.md` e este método).
3. Exemplo concreto aplicável ao tipo de demanda.
4. Inferência, sempre identificada como tal.

## Checklist final de qualidade

- [ ] O título identifica o módulo e a funcionalidade.
- [ ] O objetivo explica o problema de negócio; a User Story tem perfil, ação e benefício.
- [ ] O contexto descreve o cenário atual.
- [ ] As regras são objetivas e as condicionais trazem os dois lados (ativo e inativo).
- [ ] O fluxo operacional está em ordem cronológica.
- [ ] Telas, campos, URLs e nomes técnicos das fontes foram preservados.
- [ ] Permissões, dados (sem SQL), integrações, relatórios, logs, dependências e impactos foram avaliados.
- [ ] As exceções estão documentadas.
- [ ] Os critérios de aceite são verificáveis.
- [ ] O escopo tem limites claros (contemplado e fora do escopo).
- [ ] Nenhuma regra foi inventada e as pendências estão explícitas.
- [ ] O levantamento técnico nos repositórios foi feito (ou a ausência foi avisada); o que veio do código está como cenário atual, reaproveitamento ou pendência, nunca como requisito.
- [ ] Nenhuma pergunta aberta ao PO foi decidida em outra seção (permissões, exceções, aceites).
- [ ] Cabeçalho com estimativa em horas e responsável iguais às colunas; Base da estimativa citada.
- [ ] Nenhuma pergunta sobre Tipo, Prioridade ou Módulo.
- [ ] Emojis e títulos foram preservados.
