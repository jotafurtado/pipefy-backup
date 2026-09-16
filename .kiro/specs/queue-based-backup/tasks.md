# Plano de Implementação: Backup Baseado em Filas

## Visão Geral

Refatorar o sistema de backup do Pipefy para usar filas do Laravel, adicionar resiliência com retentativas, e criar uma página web de monitoramento na raiz do projeto.

## Tarefas

- [x]   1. Refatorar BackupPipeJob com retentativas e método failed()
    - [x] 1.1 Atualizar BackupPipeJob para incluir `$tries = 3` e `$backoff = [30, 60, 120]`
        - Adicionar propriedades de retentativa ao job
        - Implementar método `failed()` para marcar PipeBackup como "failed" definitivamente
        - Garantir que o status transita para "processing" no início e "completed"/"failed" no fim
        - _Requirements: 1.3, 2.1, 2.2_
    - [x] 1.2 Escrever teste de propriedade para transição de status do BackupPipeJob
        - **Property 2: Transição de status do BackupPipeJob**
        - **Validates: Requirements 1.3, 2.1**

- [x]   2. Refatorar PipefyBackupAllCommand para usar filas exclusivamente
    - [x] 2.1 Remover modo síncrono e opção `--queue` do PipefyBackupAllCommand
        - O comando sempre despacha para fila
        - Remover método `handleDirect()` e a opção `--queue`
        - Manter criação de PipeBackup com status "pending" antes do despacho
        - _Requirements: 1.1, 1.2_
    - [x] 2.2 Adicionar opção `--retry` ao PipefyBackupAllCommand
        - Buscar último batch_id
        - Filtrar PipeBackups com status "failed" ou "pending"
        - Resetar status para "pending" e limpar error_message
        - Redespachar BackupPipeJob para cada um
        - Exibir mensagem quando não houver pipes para reprocessar
        - _Requirements: 2.3, 2.4, 6.1, 6.2, 6.3_
    - [x] 2.3 Escrever teste de propriedade para despacho proporcional de jobs
        - **Property 1: Despacho proporcional de jobs e registros**
        - **Validates: Requirements 1.1, 1.2**
    - [x] 2.4 Escrever teste de propriedade para retry de pipes incompletos
        - **Property 3: Retry reprocessa apenas pipes incompletos**
        - **Validates: Requirements 2.3, 2.4, 6.1, 6.2**

- [x]   3. Checkpoint - Garantir que os testes passam
    - Garantir que todos os testes passam, perguntar ao usuário se houver dúvidas.

- [x]   4. Criar BackupStatusController e rotas
    - [x] 4.1 Criar BackupStatusController com métodos index() e status()
        - `index()`: retorna view com dados do batch mais recente
        - `status()`: retorna JSON com backups e resumo para polling AJAX
        - `retry()`: redespacha jobs para pipes com falha via POST
        - _Requirements: 4.1, 4.2, 4.3, 4.4, 5.3_
    - [x] 4.2 Registrar rotas no routes/web.php
        - `GET /` → `BackupStatusController@index`
        - `GET /api/backup-status` → `BackupStatusController@status`
        - `POST /api/backup-retry` → `BackupStatusController@retry`
        - _Requirements: 4.1, 4.6_
    - [x] 4.3 Escrever teste de propriedade para API de status
        - **Property 5: API de status reflete estado do banco**
        - **Validates: Requirements 4.2, 4.3, 4.4, 5.1, 5.3**

- [x]   5. Criar página de monitoramento Blade
    - [x] 5.1 Criar view backup-status.blade.php
        - Barra de progresso geral do batch
        - Tabela com pipes: nome, status, cards, attachments, erros
        - Mensagens de erro para pipes com falha
        - Botão "Reprocessar Falhas" (visível quando há falhas)
        - JavaScript para polling AJAX a cada 5 segundos atualizando a tabela e progresso
        - Usar Tailwind via CDN para estilização sem dependência de build
        - _Requirements: 4.1, 4.2, 4.3, 4.4, 4.5, 4.6, 5.1_
    - [x] 5.2 Remover a view welcome.blade.php padrão do Laravel
        - _Requirements: 4.1_

- [x]   6. Checkpoint final - Garantir que todos os testes passam
    - Garantir que todos os testes passam, perguntar ao usuário se houver dúvidas.

## Notas

- Tarefas marcadas com `*` são opcionais e podem ser puladas para um MVP mais rápido
- Cada tarefa referencia requisitos específicos para rastreabilidade
- Checkpoints garantem validação incremental
- Testes de propriedade validam propriedades universais de corretude
- Testes unitários validam exemplos específicos e edge cases
