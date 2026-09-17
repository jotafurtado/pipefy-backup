# Plano de Implementação: Backup Granular de Cards

## Visão Geral

Refatorar o sistema de backup para processar cada card como job individual na fila, adicionar rastreamento por card, retry granular e comando de verificação de integridade.

## Tasks

- [x]   1. Criar modelo PipeBackupCard e migration
    - [x] 1.1 Criar migration para tabela `pipe_backup_cards` com campos: pipe_backup_id (FK), card_id, card_title, status, attachments_count, errors_count, error_message, started_at, completed_at
        - Adicionar índices em `pipe_backup_id` e `(pipe_backup_id, status)`
        - _Requirements: 3.1_
    - [x] 1.2 Criar modelo `PipeBackupCard` com fillable, casts, e relações (`pipeBackup`, `pipeBackupErrors`)
        - _Requirements: 3.1_
    - [x] 1.3 Criar migration para adicionar coluna `pipe_backup_card_id` (nullable FK) na tabela `pipe_backup_errors`
        - _Requirements: 3.1_
    - [x] 1.4 Adicionar relação `backupCards()` no modelo `PipeBackup` e relação `pipeBackupCard()` no modelo `PipeBackupError`
        - _Requirements: 3.1_
    - [x] 1.5 Criar factory `PipeBackupCardFactory` com estados para cada status (pending, processing, completed, completed_with_errors, failed)
        - _Requirements: 3.1_

- [x]   2. Implementar BackupCardJob
    - [x] 2.1 Criar job `BackupCardJob` que recebe pipeBackupCardId, pipeId e cardData
        - Salvar card JSON em `pipefy-backup/{pipe_id}/cards/{card_id}.json`
        - Buscar attachments via `PipefyService::getCardAttachments`
        - Baixar cada attachment para `pipefy-backup/{pipe_id}/attachments/{card_id}/{filename}`
        - Atualizar PipeBackupCard com status, contagens e timestamps
        - Registrar erros no PipeBackupError vinculando ao PipeBackupCard
        - Implementar método `failed()` para marcar PipeBackupCard como "failed"
        - _Requirements: 2.1, 2.2, 2.3, 2.4, 2.5, 2.6_
    - [x] 2.2 Implementar método privado `aggregatePipeBackupStatus` no BackupCardJob
        - Recalcular cards_count, attachments_count, errors_count no PipeBackup
        - Derivar status do PipeBackup baseado nos status dos PipeBackupCard
        - _Requirements: 3.2, 3.3, 3.4_
    - [x] 2.3 Escrever teste de propriedade para salvamento do card JSON
        - **Property 2: Card JSON é salvo corretamente no disco**
        - **Validates: Requirements 2.1**
    - [x] 2.4 Escrever teste de propriedade para download de attachments
        - **Property 3: Attachments são baixados para os caminhos corretos**
        - **Validates: Requirements 2.2**
    - [x] 2.5 Escrever teste de propriedade para resiliência a falhas de attachment
        - **Property 4: Falha em um attachment não bloqueia os demais**
        - **Validates: Requirements 2.4**
    - [x] 2.6 Escrever testes unitários para edge cases do BackupCardJob
        - Testar falha na busca de attachments (status completed_with_errors)
        - Testar falha no salvamento do JSON (status failed)
        - _Requirements: 2.5, 2.6_

- [x]   3. Refatorar BackupPipeJob como Job Pai
    - [x] 3.1 Refatorar `BackupPipeJob` para buscar lista de cards e despachar `BackupCardJob` por card
        - Criar registros PipeBackupCard com status "pending" para cada card
        - Atualizar total_cards no PipeBackup
        - Tratar caso de 0 cards (marcar como completed)
        - Manter método `failed()` existente
        - _Requirements: 1.1, 1.2, 1.3, 1.4_
    - [x] 3.2 Escrever teste de propriedade para despacho proporcional do Job Pai
        - **Property 1: Despacho do Job Pai cria registros e jobs proporcionais**
        - **Validates: Requirements 1.1, 1.2**
    - [x] 3.3 Escrever testes unitários para edge cases do BackupPipeJob
        - Testar com 0 cards retornados
        - Testar falha na API
        - _Requirements: 1.3, 1.4_

- [x]   4. Checkpoint - Verificar backup granular
    - Ensure all tests pass, ask the user if questions arise.

- [x]   5. Implementar agregação de status e teste de propriedade
    - [x] 5.1 Escrever teste de propriedade para derivação de status do pipe
        - **Property 5: Status do pipe é derivado corretamente dos status dos cards**
        - **Validates: Requirements 3.2, 3.3, 3.4**

- [x]   6. Implementar retry granular de cards
    - [x] 6.1 Atualizar `BackupStatusController::retry()` para reprocessar apenas PipeBackupCard com falha
        - Resetar status dos cards com falha para "pending"
        - Despachar BackupCardJob apenas para cards com falha
        - Atualizar status do PipeBackup para "processing"
        - Tratar caso sem cards com falha
        - _Requirements: 4.1, 4.2, 4.3, 4.4_
    - [x] 6.2 Atualizar `PipefyBackupAllCommand::handleRetry()` para usar retry granular por card
        - _Requirements: 4.1, 4.2, 4.3, 4.4_
    - [x] 6.3 Escrever teste de propriedade para retry seletivo
        - **Property 6: Retry reprocessa apenas cards com falha**
        - **Validates: Requirements 4.1, 4.2, 4.3**
    - [x] 6.4 Escrever teste unitário para edge case de retry sem falhas
        - _Requirements: 4.4_

- [x]   7. Checkpoint - Verificar retry granular
    - Ensure all tests pass, ask the user if questions arise.

- [x]   8. Implementar comando de verificação de integridade
    - [x] 8.1 Criar job `VerifyCardBackupJob` que verifica JSON e attachments de um card em disco
        - Verificar existência e validade do card JSON
        - Verificar existência de cada attachment referenciado no JSON
        - Registrar problemas via Log::warning
        - _Requirements: 5.2, 5.3, 5.4, 5.5_
    - [x] 8.2 Criar comando `PipefyVerifyBackupCommand` (`pipefy:verify-backup {pipe_id}`)
        - Ler index.json do pipe
        - Despachar VerifyCardBackupJob para cada card
        - Exibir resumo de verificação
        - _Requirements: 5.1, 5.6_
    - [x] 8.3 Escrever teste de propriedade para verificação de integridade
        - **Property 7: Verificação detecta JSON e attachments corretamente**
        - **Validates: Requirements 5.2, 5.3**
    - [x] 8.4 Escrever testes unitários para edge cases da verificação
        - Testar com JSON ausente
        - Testar com JSON inválido
        - Testar com attachment ausente
        - Testar com index.json ausente
        - _Requirements: 5.4, 5.5_

- [x]   9. Atualizar interface de status
    - [x] 9.1 Atualizar `BackupStatusController::status()` para incluir contagens de cards por status no JSON de resposta
        - _Requirements: 6.1, 6.2_
    - [x] 9.2 Atualizar `backup-status.blade.php` para exibir progresso por cards (pending, processing, completed, failed) em cada pipe
        - _Requirements: 6.1_
    - [x] 9.3 Escrever teste de propriedade para API de status
        - **Property 8: API de status retorna contagens corretas por status de card**
        - **Validates: Requirements 6.1, 6.2**

- [x]   10. Remover código legado e atualizar index.json
    - [x] 10.1 Atualizar geração do `index.json` para ser feita pelo BackupPipeJob após todos os cards serem processados, ou pelo último BackupCardJob a concluir
        - Manter compatibilidade com o comando de verificação
        - _Requirements: 5.1_
    - [x] 10.2 Remover ou depreciar `PipefyBackupCardsCommand` que processava todos os cards sequencialmente
        - Garantir que nenhuma outra parte do código depende deste comando
        - _Requirements: 1.1_

- [x]   11. Checkpoint final - Verificar tudo
    - Ensure all tests pass, ask the user if questions arise.

## Notas

- Tasks marcadas com `*` são opcionais e podem ser puladas para um MVP mais rápido
- Cada task referencia requisitos específicos para rastreabilidade
- Checkpoints garantem validação incremental
- Testes de propriedade validam propriedades universais de corretude
- Testes unitários validam exemplos específicos e edge cases
