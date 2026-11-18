# 📋 Modelo de referência — Escopo Funcional V1

> Exemplo **ilustrativo** (padrão de Consultoria Funcional YUV). Use estrutura, linguagem, organização, emojis e nível de detalhe como referência. **Não copie os requisitos para outros cards.**
> Documento original: "YUV | PADRÃO DE CONSULTORIA FUNCIONAL — Exemplo de Escopo Funcional V1 | Status: Aguardando validação do PO".

# [YUV - Automações] – Alerta de Equipamento Sem Comunicação

**Tipo:** Feature
**Prioridade:** Conforme card
**Severidade:** Conforme card
**Estimativa:** 8h
**Responsável sugerido:** Nome do dev

## 🎯 1. Objetivo

Implementar uma automação que permita monitorar o tempo sem comunicação dos equipamentos, gerando alertas quando o período configurado pelo usuário for ultrapassado.

A funcionalidade tem como objetivo facilitar a identificação de equipamentos que deixaram de transmitir informações à plataforma, permitindo uma atuação preventiva dos gestores de frota.

## 👤 2. User Story

**Como** gestor de frota,
**Quero** configurar alertas para equipamentos que permaneçam sem comunicação por determinado período,
**Para** identificar possíveis falhas de conectividade e tomar as ações necessárias.

## 📌 3. Contexto / Cenário Atual

A plataforma YUV registra informações de comunicação dos equipamentos, incluindo a data e hora do último heartbeat recebido.

A proposta consiste em utilizar essas informações para identificar automaticamente equipamentos que ultrapassem o tempo máximo sem comunicação definido pelo usuário.

A automação deverá considerar os equipamentos selecionados e as configurações estabelecidas no momento do cadastro.

## ⚙️ 4. Regras de Negócio

**RN01 – Configuração da automação**

- Permitir criar uma automação do tipo "Equipamento sem comunicação".
- Permitir informar o período máximo sem comunicação.
- Permitir selecionar os equipamentos monitorados.

**RN02 – Monitoramento da comunicação**

- Considerar o último heartbeat registrado.
- Comparar a última comunicação com o horário atual.
- Identificar quando o período configurado for atingido.

**RN03 – Geração do evento**

- Gerar um evento quando o equipamento ultrapassar o tempo configurado.
- Associar o evento ao equipamento e à automação responsável.

**RN04 – Controle de duplicidade**

- Impedir a geração contínua de eventos para a mesma ocorrência.
- Permitir uma nova ocorrência após o restabelecimento da comunicação.

**RN05 – Restabelecimento da comunicação**

- Identificar o retorno da comunicação.
- Encerrar a ocorrência ativa.
- Registrar a data e hora do restabelecimento.

## 🔄 5. Fluxo Operacional

1. Usuário acessa o módulo de Automações.
2. Seleciona a opção de criar uma nova automação.
3. Escolhe o gatilho "Equipamento sem comunicação".
4. Define o tempo máximo permitido sem comunicação.
5. Seleciona os equipamentos monitorados.
6. Salva e ativa a automação.
7. O sistema monitora os registros de heartbeat.
8. Quando o limite é atingido, gera o evento correspondente.
9. Ao identificar o retorno da comunicação, encerra a ocorrência.

## 🖥️ 6. Telas Impactadas

**Automações – Cadastro/Edição**

- Disponibilizar o gatilho de ausência de comunicação.
- Apresentar os parâmetros necessários para configuração.

**Automações – Listagem**

- Permitir visualizar a automação cadastrada.
- Manter as operações existentes de edição e ativação/desativação.

## 🧩 7. Campos e Parâmetros

**Tipo de gatilho:** Equipamento sem comunicação.

**Tempo limite:** Período máximo permitido sem heartbeat.

**Equipamentos:** Seleção dos dispositivos monitorados.

**Status:** Ativa ou inativa.

As validações de limites e obrigatoriedade deverão seguir as definições funcionais aprovadas.

## 🔐 8. Permissões e Controle de Acesso

- Respeitar as permissões existentes no módulo de Automações.
- Permitir selecionar somente equipamentos disponíveis no contexto de acesso do usuário.
- Preservar as restrições existentes entre clientes e revendedores.

## 🗄️ 9. Estrutura de Dados

Utilizar os dados existentes de comunicação dos equipamentos.

Informações funcionais necessárias:

- Identificação do equipamento.
- Último heartbeat.
- Identificação da automação.
- Data e hora do evento.
- Estado da ocorrência.

**Definição técnica:** validar com o desenvolvimento o reaproveitamento das estruturas existentes e a necessidade de persistência adicional.

## 🌐 10. APIs e Integrações

Avaliar o reaproveitamento dos serviços existentes de automações e notificações.

A necessidade de novos endpoints ou alterações nos contratos deverá ser definida durante o refinamento técnico.

## 📊 11. Relatórios

Não se aplica à primeira versão.

## 🚨 12. Tratamento de Exceções

**EX01 – Equipamento sem histórico de comunicação**

- **Condição:** equipamento sem nenhum heartbeat registrado.
- **Comportamento esperado:** pendente de validação do PO.
- **Mensagem/Retorno:** não se aplica.

**EX02 – Automação desativada**

- **Condição:** automação com status inativo.
- **Comportamento esperado:** não gerar novos eventos.
- **Mensagem/Retorno:** não se aplica.

**EX03 – Falha no processamento**

- **Condição:** erro ao processar a automação.
- **Comportamento esperado:** registrar a falha para diagnóstico, sem gerar evento duplicado.
- **Mensagem/Retorno:** não se aplica.

## 📝 13. Logs e Auditoria

Registrar, conforme os mecanismos disponíveis:

- Identificação da automação.
- Equipamento relacionado.
- Data e hora da ocorrência.
- Resultado do processamento.
- Data e hora do restabelecimento.

## 🔗 14. Dependências

- Disponibilidade dos registros de heartbeat.
- Mecanismo de processamento das automações.
- Serviço de notificações existente.

## 📌 15. Impactos

**Frontend:** configuração e apresentação do novo gatilho.

**Backend:** processamento e identificação dos períodos sem comunicação.

**Banco de dados:** possível necessidade de persistência das ocorrências.

**Integrações:** utilização dos mecanismos existentes de notificação.

## 🧪 16. Critérios de Aceite

- [ ] Permitir criar uma automação de ausência de comunicação.
- [ ] Permitir configurar o tempo limite.
- [ ] Permitir selecionar os equipamentos monitorados.
- [ ] Identificar corretamente o tempo sem heartbeat.
- [ ] Gerar evento quando o limite for atingido.
- [ ] Impedir eventos duplicados para a mesma ocorrência.
- [ ] Identificar o restabelecimento da comunicação.
- [ ] Permitir ativar e desativar a automação.
- [ ] Respeitar as permissões de acesso.
- [ ] Preservar o funcionamento das demais automações.

## 📎 17. Observações Finais

**Escopo Contemplado**

- Gatilho "Equipamento sem comunicação" com tempo limite e seleção de equipamentos.
- Geração e encerramento da ocorrência.

**Fora do Escopo**

- Novos canais de comunicação.

**Base da estimativa:** &lt;id&gt; &lt;nome do card de referência&gt; (Xh reais); &lt;id&gt; &lt;nome&gt; (Yh reais). Diferença de abrangência em relação à demanda, se houver.

**Responsável sugerido:** Nome do dev — atuou em X e Y (cards de referência).

**Considerações**

- Priorizar o reaproveitamento dos mecanismos existentes.
- Sugestão: validar com o desenvolvimento a estratégia de processamento.

## ❓ Pontos para Validação do PO

- Qual deverá ser o comportamento para equipamentos que nunca comunicaram?
- Quais limites mínimo e máximo poderão ser configurados?
- Quais canais de notificação deverão ser utilizados?

> Não pergunte sobre Tipo, Prioridade ou Módulo do card: o PO orientou ignorar perguntas de metadados.

## 🛠️ Pendências Técnicas

- Validar a estratégia de processamento do gatilho.
- Verificar o reaproveitamento das estruturas existentes.
- Avaliar a necessidade de novos serviços ou alterações de banco.
