# Referência da plataforma YUV (resumo do Engineering Handbook v1.0)

> Resumo do "YUV Engineering Handbook" (Engineering Team, 2026-08-01) para escrever escopos com a linguagem da plataforma.
> Regra do handbook: **código > handbook**. Use estes dados para nomear telas, módulos, dependências e impactos — **nunca** para afirmar como algo funciona hoje sem evidência no card. Na dúvida, vira Ponto para Validação do PO ou Pendência Técnica.

## O que é a plataforma

Telemática e gestão de frota **B2B multi-tenant**: rastreamento GPS, vídeo (ao vivo e playback), alarmes, ocorrências, comandos remotos, geocercas, checklists, billing e gamificação de motoristas.

| Camada | Repositório | Stack |
|---|---|---|
| Web (operadores) | `yuv-fe` | Next.js 14 |
| API | `yuv-be` | Laravel 12 (API-only, JWT) |
| App do motorista | `yuv-app-driver` | Expo / React Native |
| Ingestão e processamento | vários serviços | Go (integradores, consumers, crons) |
| Banco | `yuv-migrations` | MySQL compartilhado (+ MongoDB para dados brutos, Redis para cache) |
| Deploy | `yuv-cluster` | Kustomize / ArgoCD (OKE) |

Caminho do dado: **Equipamento → Gateway / IoT Hub / integrador → RabbitMQ + MySQL → API Laravel → Web/App**. O frontend só conversa com a API.

## Hierarquia e perfis (multi-tenant)

**Manager (YUV) → Dealer (revendedor) → Customer (cliente) → Branch (filial).**
Tipos de usuário: Admin/Dev, Manager, Dealer, Customer, Branch, operador multi-cliente. Permissões por **grupos de permissão** (RBAC) + policies; o menu do web depende do tipo de usuário e das permissões.
Em escopo: sempre considerar "respeitar o contexto de acesso do usuário" e "restrições entre clientes e revendedores".

## Glossário (linguagem ubíqua)

| Termo | Significado |
|---|---|
| **Asset (Ativo)** | Veículo / unidade de frota monitorada; pode ter um Device vinculado |
| **Device (Equipamento)** | Dispositivo IoT (IMEI, modelo, fabricante); identidade técnica |
| **Alarm (Alarme)** | Evento bruto de alarme recebido do equipamento |
| **Occurrence (Ocorrência)** | Agrupamento de alarmes para tratamento operacional (configurável por cliente) |
| **Telemetry** | GPS/CAN e leituras de campo (tabela `gps`, último GPS no ativo) |
| **Geofence (Cerca)** | Cerca geográfica (círculo/polígono/retângulo) + automações |
| **Driver (Motorista)** | Login por RFID / app do motorista; checklists, FaceID, gamificação (YUV XP) |
| **Live Data** | Rastreamento em tempo real via WebSocket (`yuv-live-data`) |
| **Gateway / IoT Hub** | Borda de protocolos de campo (JT808/JTT/VL…) / hub Jimi |
| **Address Resolver** | Geocodificação reversa assíncrona (endereço da posição) |
| **Heartbeat** | Sinal de vida do equipamento; online = último heartbeat ≤ 300 s |
| **Comandos** | Fila `command_queue` + chat de comandos com o equipamento |

Fabricantes/integrações: Jimi/JTT (IoT Hub), Streamax, Hikvision, Virloc, gateways próprios; mapas Google/HERE/Nominatim; armazenamento OCI.

## Domínios e telas do web (`yuv-fe`)

- **Home / Resumo**, **Tracking** (mapa, ao vivo), **BI**, **Relatórios** (`/reports/*`: posições, deslocamento/movimento, alarmes, telemetria, exportação assíncrona Excel/PDF).
- **Streaming / Playback** (vídeo HLS/FLV).
- **Alarmes** (`/alarms`, relatório bruto) e **Ocorrências** (`/occurrences`, tratamento).
- **Cadastros:** ativos e grupos de ativos, equipamentos, motoristas, chips (SIM), filiais, geocercas.
- **Comandos**, **Manutenção**, **Checklists**, **FaceID**, **Gamificação**.
- **Automações** (gatilhos ligados a geocercas e afins; avaliação feita por serviço próprio).
- **Manager:** revendedores, billing, white-label (tema por revendedor), webhooks de revendedor, chaves de API.
- **Admin:** usuários, módulos, permissões, parâmetros.
- Idiomas: pt / en / es. PWA.

## App do motorista (`yuv-app-driver`)

Login (JWT próprio), checklist com fotos, FaceID, notificações (FCM + TTS), gamificação. Mesma API, middleware específico de motorista.

## Fluxos úteis para escopo

- **Posição (telemetria):** equipamento → integrador → grava `gps` → endereço preenchido depois (assíncrono) → relatórios / mapa ao vivo.
- **Alarme → Ocorrência:** alarme gravado → serviço agrupa a cada ~2 s conforme configuração do cliente → ocorrência → notificação (push/e-mail) → tela de Ocorrências.
- **Geocerca:** configuração e automações no web/API; avaliação de entrada/saída em serviço separado (pode gerar alarme).
- **Exportação de relatório:** pedido fica pendente → serviço gera arquivo → disponibiliza para download.
- **Cadastro de equipamento:** pode provisionar no fabricante (Streamax/Hik) no momento do cadastro.
- **Movimento/"viagem":** não existe entidade Trip; deslocamento/parada é calculado pela ignição sobre o GPS.

## Como usar nas seções do escopo

| Seção | Como o handbook ajuda |
|---|---|
| 🖥️ Telas Impactadas | Nomear a tela/módulo existente (Tracking, Relatórios, Cadastro de Equipamentos, Automações…) |
| 🔐 Permissões | Hierarquia Manager/Dealer/Customer/Branch + grupos de permissão |
| 🗄️ Estrutura de Dados | Entidades existentes (Asset, Device, Alarm, Occurrence, gps…) — persistência nova = pendência técnica |
| 🌐 APIs e Integrações | API Laravel, integrações de fabricante, serviços de notificação/exportação |
| 🔗 Dependências / 📌 Impactos | Frontend web, app do motorista, backend, serviços de processamento, banco |
| 🛠️ Pendências Técnicas | Pontos que dependem de serviços sem documentação (gateway, live data, geocercas runtime, notificações) |
