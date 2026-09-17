# Plano de Implementação: Pipefy Backup All

## Visão Geral

Implementação incremental do backup completo de todos os pipes. Primeiro o model e migration para rastreamento de status, depois o job de backup, os dois comandos Artisan e por fim os testes. A lógica de backup por pipe é reutilizada do `pipefy:backup-cards` existente via `Artisan::call()`.

## Tarefas

- [x]   1. Criar Model PipeBackup e migration
    - [x] 1.1 Criar migration `create_pipe_backups_table`
        - Tabela `pipe_backups` com colunas: id, batch_id (string 36, indexado), pipe_id (unsigned integer), pipe_name (string), status (string 20, default 'pending'), error_message (text nullable), started_at (timestamp nullable), completed_at (timestamp nullable), timestamps
        - Índice composto em `[batch_id, status]`
        - _Requisitos: 4.1_
    - [x] 1.2 Criar Model `PipeBackup` em `app/Models/PipeBackup.php`
        - Definir `$fillable` com: batch_id, pipe_id, pipe_name, status, error_message, started_at, completed_at
        - Definir método `casts()` com: pipe_id → integer, started_at → datetime, completed_at → datetime
        - _Requisitos: 4.1_
    - [x] 1.3 Criar Factory `PipeBackupFactory` para o model
        - Gerar dados fake para todos os campos, com status aleatório entre pending, processing, completed, failed
        - _Requisitos: 4.1_

- [x]   2. Criar Job BackupPipeJob
    - [x] 2.1 Criar `app/Jobs/BackupPipeJob.php`
        - Implementar `ShouldQueue` com propriedades `pipeBackupId` e `pipeId` via constructor promotion
        - No `handle()`: buscar PipeBackup, atualizar status para `processing` com `started_at`, chamar `Artisan::call('pipefy:backup-cards', ['pipe_id' => $this->pipeId])`
        - Em caso de sucesso: atualizar status para `completed` com `completed_at`
        - Em caso de falha: capturar exceção, atualizar status para `failed` com `error_message` e `completed_at`, registrar no log
        - _Requisitos: 3.4, 3.5, 4.2, 4.3, 4.4_
    - [ ]\* 2.2 Escrever teste de propriedade para transições de estado do job
        - **Propriedade 7: Transições de estado do job**
        - Gerar pipes aleatórios, criar PipeBackup com status pending, executar BackupPipeJob com mock de sucesso/falha, verificar que status transiciona corretamente (processing → completed ou processing → failed) com timestamps preenchidos
        - **Valida: Requisitos 4.2, 4.3, 4.4**
    - [ ]\* 2.3 Escrever testes unitários para BackupPipeJob
        - Testar que job chama `Artisan::call('pipefy:backup-cards')` com pipe_id correto (Requisito 3.4)
        - Testar que job atualiza status para failed quando Artisan::call lança exceção (Requisito 3.5)
        - Testar que job atualiza status para failed quando Artisan::call retorna código != 0 (Requisito 3.5)
        - _Requisitos: 3.4, 3.5_

- [x]   3. Checkpoint - Verificar testes do model e job
    - Garantir que todos os testes passam, perguntar ao usuário se houver dúvidas.

- [x]   4. Criar comando PipefyBackupAllCommand
    - [x] 4.1 Criar `app/Console/Commands/PipefyBackupAllCommand.php`
        - Assinatura: `pipefy:backup-all {--queue : Despachar backups para a fila}`
        - Injetar `PipefyService` via `handle()`
        - Obter `organization_id` de `config('services.pipefy.organization_id')`
        - Validar que `organization_id` existe, retornar FAILURE se ausente
        - Chamar `getPipes($organizationId)`, tratar exceção com mensagem de erro
        - Se zero pipes: exibir mensagem informativa, retornar SUCCESS
        - Delegar para `handleDirect()` ou `handleQueue()` conforme flag `--queue`
        - _Requisitos: 1.1, 1.2, 1.3, 1.4, 1.5_
    - [x] 4.2 Implementar modo direto (`handleDirect`)
        - Registrar tempo de início com `microtime(true)`
        - Iterar sobre cada pipe com output: "Processando pipe X de Y: {nome} (ID: {id})"
        - Chamar `Artisan::call('pipefy:backup-cards', ['pipe_id' => $pipe['id']])` para cada pipe
        - Capturar exceções e exit codes != 0: registrar no log, exibir aviso, continuar
        - Manter contadores de sucesso e falha
        - Exibir resumo final: total processados, sucesso, falhas, tempo de execução
        - _Requisitos: 2.1, 2.2, 2.3, 2.4, 6.1, 6.3_
    - [x] 4.3 Implementar modo fila (`handleQueue`)
        - Gerar `batch_id` via `Str::uuid()->toString()`
        - Para cada pipe: criar registro `PipeBackup` com status `pending` e despachar `BackupPipeJob`
        - Exibir tabela com pipes enfileirados (ID, Nome)
        - Exibir total de jobs despachados e instrução: "Execute `php artisan pipefy:backup-status` para acompanhar o progresso."
        - _Requisitos: 3.1, 3.2, 3.3, 4.5_
    - [ ]\* 4.4 Escrever teste de propriedade para modo direto processa todos os pipes
        - **Propriedade 1: Modo direto processa todos os pipes**
        - Gerar N pipes aleatórios (1-20), mockar PipefyService::getPipes e Artisan::call, verificar que backup-cards foi chamado N vezes com os pipe_ids corretos
        - **Valida: Requisitos 2.1**
    - [ ]\* 4.5 Escrever teste de propriedade para progresso no terminal
        - **Propriedade 2: Progresso do modo direto no terminal**
        - Gerar N pipes com nomes aleatórios, executar comando, verificar que output contém "pipe X de N" e o nome de cada pipe
        - **Valida: Requisitos 2.2**
    - [ ]\* 4.6 Escrever teste de propriedade para resiliência no modo direto
        - **Propriedade 3: Resiliência no modo direto**
        - Gerar N pipes, configurar subset aleatório para falhar via mock, verificar que todos N pipes foram tentados (Artisan::call chamado N vezes)
        - **Valida: Requisitos 2.3, 6.1**
    - [ ]\* 4.7 Escrever teste de propriedade para precisão do resumo
        - **Propriedade 4: Precisão do resumo no modo direto**
        - Gerar N pipes com S sucessos e F falhas aleatórios, verificar que output contém contagens corretas de sucesso e falha
        - **Valida: Requisitos 2.4, 6.3**
    - [ ]\* 4.8 Escrever teste de propriedade para modo fila despacha jobs
        - **Propriedade 5: Modo fila despacha um job por pipe**
        - Gerar N pipes, executar com --queue usando Queue::fake, verificar que N BackupPipeJob foram despachados com pipe_ids corretos
        - **Valida: Requisitos 3.1**
    - [ ]\* 4.9 Escrever teste de propriedade para registros pendentes com batch_id
        - **Propriedade 6: Registros pendentes com mesmo batch_id**
        - Gerar N pipes, executar com --queue, verificar que N registros PipeBackup existem com mesmo batch_id, status pending e pipe_id/pipe_name corretos
        - **Valida: Requisitos 4.5**
    - [ ]\* 4.10 Escrever teste de propriedade para saída do modo fila
        - **Propriedade 9: Saída do modo fila contém informações dos pipes**
        - Gerar N pipes com nomes aleatórios, executar com --queue, verificar que output contém ID e nome de cada pipe
        - **Valida: Requisitos 3.2**
    - [ ]\* 4.11 Escrever testes unitários para o comando BackupAll
        - Testar que comando usa organization_id da configuração (Requisito 1.1)
        - Testar organização com zero pipes exibe mensagem e retorna SUCCESS (Requisito 1.3)
        - Testar configuração organization_id ausente retorna FAILURE (Requisito 1.4)
        - Testar erro ao consultar pipes retorna FAILURE (Requisito 1.5)
        - Testar modo fila exibe instruções para pipefy:backup-status (Requisito 3.3)
        - _Requisitos: 1.1, 1.3, 1.4, 1.5, 3.3_

- [x]   5. Checkpoint - Verificar testes do comando BackupAll
    - Garantir que todos os testes passam, perguntar ao usuário se houver dúvidas.

- [x]   6. Criar comando PipefyBackupStatusCommand
    - [x] 6.1 Criar `app/Console/Commands/PipefyBackupStatusCommand.php`
        - Assinatura: `pipefy:backup-status`
        - Buscar batch_id mais recente via `PipeBackup::latest()->value('batch_id')`
        - Se não houver registros: exibir mensagem informativa, retornar SUCCESS
        - Buscar todos os registros do lote e exibir tabela com: Pipe ID, Nome, Status, Início, Conclusão, Erro
        - Exibir resumo: total, completados, em processamento, pendentes, com falha
        - _Requisitos: 5.1, 5.2, 5.3, 5.4_
    - [ ]\* 6.2 Escrever teste de propriedade para precisão do comando de status
        - **Propriedade 8: Precisão do comando de status**
        - Gerar registros PipeBackup com distribuição aleatória de status, executar backup-status, verificar que contagens de resumo correspondem à distribuição real
        - **Valida: Requisitos 5.2, 5.3**
    - [ ]\* 6.3 Escrever testes unitários para o comando BackupStatus
        - Testar que comando está registrado como pipefy:backup-status (Requisito 5.1)
        - Testar nenhum registro exibe mensagem informativa (Requisito 5.4)
        - Testar que tabela exibe dados corretos para um lote específico (Requisito 5.2)
        - _Requisitos: 5.1, 5.2, 5.4_

- [x]   7. Checkpoint final - Verificar todos os testes
    - Garantir que todos os testes passam, perguntar ao usuário se deseja rodar a suite completa.

## Notas

- Tarefas marcadas com `*` são opcionais e podem ser puladas para um MVP mais rápido
- Cada tarefa referencia requisitos específicos para rastreabilidade
- Checkpoints garantem validação incremental
- Testes de propriedade validam propriedades universais de corretude (mínimo 100 iterações com Faker)
- Testes unitários validam exemplos específicos e edge cases
- A lógica de backup por pipe é reutilizada do `pipefy:backup-cards` via `Artisan::call()` — sem duplicação de código
- O banco de dados é SQLite e a conexão de fila é `database` — a migration deve ser compatível com SQLite
