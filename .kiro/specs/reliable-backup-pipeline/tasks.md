# Plano de Implementação: Pipeline de Backup Confiável

## Visão Geral

Correções incrementais no pipeline de backup existente para resolver problemas de timeout, cards órfãos, schema e monitoramento. Cada tarefa constrói sobre a anterior, com testes validando cada mudança.

## Tarefas

- [-] 1. Corrigir configuração de timeout da fila e schema do banco
    - [x] 1.1 Alterar `retry_after` em `config/queue.php` para usar `env('DB_QUEUE_RETRY_AFTER', 3600)` e adicionar `DB_QUEUE_RETRY_AFTER=3600` ao `.env`
        - _Requirements: 1.1, 1.2_
    - [-] 1.2 Criar migration para alterar coluna `current_step` de `string(50)` para `string(255)` na tabela `pipe_backups`
        - Usar `php artisan make:migration alter_current_step_column_in_pipe_backups_table --table=pipe_backups`
        - _Requirements: 3.1, 3.2_
    - [ ]\* 1.3 Escrever teste de propriedade para persistência round-trip de `current_step`
        - **Property 4: Persistência round-trip de current_step**
        - Gerar strings aleatórias de 1 a 255 caracteres, salvar em PipeBackup.current_step, ler do banco e verificar igualdade
        - **Validates: Requirements 3.1, 3.2**

- [ ]   2. Extrair lógica de recálculo de status para o modelo PipeBackup
    - [ ] 2.1 Criar método `recalculateStatus()` no modelo `PipeBackup` extraindo a lógica de `BackupCardJob::aggregatePipeBackupStatus()`
        - Incluir geração do index.json no método (mover `generateIndexJson` também)
        - _Requirements: 2.3, 5.3_
    - [ ] 2.2 Atualizar `BackupCardJob` para chamar `$pipeBackup->recalculateStatus()` em vez da lógica inline
        - Remover os métodos `aggregatePipeBackupStatus()` e `generateIndexJson()` do BackupCardJob
        - _Requirements: 2.3, 5.3_
    - [ ]\* 2.3 Escrever teste de propriedade para `recalculateStatus()`
        - **Property 3: Recálculo de status agregado do PipeBackup**
        - Gerar PipeBackups com cards em diferentes combinações de status terminais, chamar recalculateStatus(), verificar cards_count e status final
        - **Validates: Requirements 2.3, 5.3**

- [ ]   3. Checkpoint - Garantir que todos os testes passam
    - Executar `php artisan test --compact`, perguntar ao usuário se houver dúvidas.

- [ ]   4. Criar comando de recuperação de cards e pipes órfãos
    - [ ] 4.1 Criar comando `RecoverOrphanedCardsCommand` com signature `pipefy:recover-orphans {--timeout=30}`
        - Usar `php artisan make:command RecoverOrphanedCardsCommand`
        - Buscar PipeBackupCards com status "processing" e updated_at < now() - timeout minutos
        - Marcar como "failed" com mensagem descritiva
        - Chamar `recalculateStatus()` nos PipeBackups afetados
        - Buscar PipeBackups em "processing" sem cards pendentes/processando e forçar conclusão
        - Exibir resumo da operação
        - _Requirements: 2.1, 2.2, 2.3, 2.4, 5.1, 5.2, 5.3, 5.4_
    - [ ]\* 4.2 Escrever teste de propriedade para detecção de cards órfãos
        - **Property 2: Comando de recuperação marca apenas cards órfãos**
        - Gerar cards com diferentes status e timestamps, executar comando, verificar que apenas os corretos foram marcados
        - **Validates: Requirements 2.1, 2.2, 5.1, 5.2**
    - [ ]\* 4.3 Escrever testes de edge case para o comando de recuperação
        - Testar: nenhum card órfão encontrado, todos os cards já em status terminal, pipe sem cards
        - _Requirements: 2.4, 5.4_

- [ ]   5. Checkpoint - Garantir que todos os testes passam
    - Executar `php artisan test --compact`, perguntar ao usuário se houver dúvidas.

- [ ]   6. Melhorar o endpoint da API de status e o retry
    - [ ] 6.1 Atualizar `BackupStatusController::status()` para incluir `elapsed_seconds` e `is_stale` na resposta JSON
        - `elapsed_seconds`: diferença em segundos entre now() e started_at para pipes em processamento
        - `is_stale`: true quando pipe está em "processing" e updated_at > 30 minutos atrás
        - _Requirements: 7.2, 7.3_
    - [ ]\* 6.2 Escrever teste de propriedade para campos calculados da API
        - **Property 6: Campos calculados da API de status**
        - Gerar PipeBackups com diferentes started_at e updated_at, verificar elapsed_seconds e is_stale
        - **Validates: Requirements 7.2, 7.3**
    - [ ]\* 6.3 Escrever teste de propriedade para contagens de cards na API
        - **Property 7: Contagens de cards por status na API**
        - Gerar PipeBackups com cards em diferentes status, verificar que card_status_summary soma corretamente
        - **Validates: Requirements 4.2**
    - [ ]\* 6.4 Escrever teste de propriedade para retry inteligente
        - **Property 5: Retry inteligente redespacha apenas cards com falha**
        - Gerar PipeBackups com mix de cards completed/failed, executar retry, verificar que apenas failed foram redespachados
        - **Validates: Requirements 6.1, 6.3**

- [ ]   7. Atualizar o dashboard com melhorias visuais
    - [ ] 7.1 Atualizar `backup-status.blade.php` para exibir tempo decorrido, indicador de alerta para pipes stale, e feedback visual no retry
        - Formatar elapsed_seconds como "Xm Ys"
        - Borda amarela/vermelha para pipes com is_stale = true
        - Spinner e mensagem ao clicar retry
        - _Requirements: 4.4, 7.1, 7.2, 7.3, 7.4_

- [ ]   8. Teste de falha do BackupPipeJob
    - [ ]\* 8.1 Escrever teste de propriedade para falha do BackupPipeJob
        - **Property 1: Falha do job marca PipeBackup como failed**
        - Gerar PipeBackups em processing, invocar failed() com exceção aleatória, verificar status e error_message
        - **Validates: Requirements 1.3**

- [ ]   9. Checkpoint final - Garantir que todos os testes passam
    - Executar `php artisan test --compact`, perguntar ao usuário se houver dúvidas.

## Notas

- Tarefas marcadas com `*` são opcionais e podem ser puladas para um MVP mais rápido
- Cada tarefa referencia requisitos específicos para rastreabilidade
- Checkpoints garantem validação incremental
- Testes de propriedade validam propriedades universais de corretude
- Testes unitários validam exemplos específicos e edge cases
