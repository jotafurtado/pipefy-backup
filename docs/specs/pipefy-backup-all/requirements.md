# Documento de Requisitos

## Introdução

Este documento descreve os requisitos para a funcionalidade de backup completo de todos os pipes de uma organização no Pipefy. O objetivo é criar um comando Artisan `pipefy:backup-all` que orquestre o backup de todos os pipes, reutilizando a lógica existente do `pipefy:backup-cards`. A funcionalidade suporta dois modos de execução: direto (sequencial no terminal) e via fila (queue), com um comando auxiliar `pipefy:backup-status` para monitoramento do progresso dos backups enfileirados.

## Glossário

- **Serviço_Pipefy**: Classe de serviço existente (`PipefyService`) responsável pela comunicação com a API GraphQL do Pipefy
- **Comando_BackupAll**: Comando Artisan `pipefy:backup-all` que orquestra o backup de todos os pipes da organização
- **Comando_BackupCards**: Comando Artisan existente `pipefy:backup-cards` que executa o backup de cards de um pipe individual
- **Comando_BackupStatus**: Comando Artisan `pipefy:backup-status` que exibe o status dos backups em andamento
- **Job_BackupPipe**: Job Laravel que processa o backup de um pipe individual na fila
- **Modelo_PipeBackup**: Model Eloquent que rastreia o status de backup de cada pipe
- **Organização**: Organização no Pipefy, identificada por um ID numérico configurado em `services.pipefy.organization_id`
- **Pipe**: Processo ou fluxo de trabalho dentro de uma Organização no Pipefy
- **Modo_Direto**: Modo de execução padrão onde o backup é processado sequencialmente no terminal
- **Modo_Fila**: Modo de execução ativado pela flag `--queue`, onde cada pipe é processado como um job na fila

## Requisitos

### Requisito 1: Listagem de Pipes da Organização

**User Story:** Como usuário, eu quero que o comando identifique automaticamente todos os pipes da organização, para que eu não precise informar cada pipe manualmente.

#### Critérios de Aceitação

1. WHEN o Comando_BackupAll for executado, THEN THE Comando_BackupAll SHALL obter o ID da organização a partir da configuração `services.pipefy.organization_id`
2. WHEN o Comando_BackupAll possuir o ID da organização, THEN THE Comando_BackupAll SHALL chamar `PipefyService::getPipes()` para obter a lista completa de pipes
3. WHEN a organização possuir zero pipes, THEN THE Comando_BackupAll SHALL exibir uma mensagem informativa e encerrar com código de sucesso
4. IF a configuração `services.pipefy.organization_id` estiver ausente, THEN THE Comando_BackupAll SHALL exibir uma mensagem de erro e retornar código de saída diferente de zero
5. IF ocorrer um erro ao consultar os pipes da organização, THEN THE Comando_BackupAll SHALL exibir a mensagem de erro e retornar código de saída diferente de zero

### Requisito 2: Execução em Modo Direto (Sequencial)

**User Story:** Como usuário, eu quero executar o backup de todos os pipes sequencialmente no terminal, para que eu possa acompanhar o progresso em tempo real.

#### Critérios de Aceitação

1. WHEN o Comando_BackupAll for executado sem a flag `--queue`, THEN THE Comando_BackupAll SHALL processar cada pipe sequencialmente, reutilizando a lógica de backup do Comando_BackupCards
2. WHILE o Comando_BackupAll estiver processando pipes no Modo_Direto, THE Comando_BackupAll SHALL exibir o progresso geral indicando qual pipe está sendo processado (pipe X de Y) e o nome do pipe
3. WHEN o backup de um pipe individual falhar no Modo_Direto, THEN THE Comando_BackupAll SHALL registrar o erro, exibir um aviso no terminal e continuar com o próximo pipe
4. WHEN todos os pipes forem processados no Modo_Direto, THEN THE Comando_BackupAll SHALL exibir um resumo contendo: total de pipes processados, pipes com sucesso, pipes com falha e tempo total de execução

### Requisito 3: Execução em Modo Fila (Queue)

**User Story:** Como usuário, eu quero poder despachar o backup de cada pipe como um job na fila, para que o processamento ocorra em background sem bloquear o terminal.

#### Critérios de Aceitação

1. WHEN o Comando_BackupAll for executado com a flag `--queue`, THEN THE Comando_BackupAll SHALL despachar um Job_BackupPipe para cada pipe na fila
2. WHEN o Comando_BackupAll despachar jobs na fila, THEN THE Comando_BackupAll SHALL exibir a lista de pipes enfileirados com seus respectivos IDs e nomes
3. WHEN o Comando_BackupAll concluir o despacho dos jobs, THEN THE Comando_BackupAll SHALL exibir o total de jobs despachados e instruções para monitorar o progresso via `pipefy:backup-status`
4. THE Job_BackupPipe SHALL processar o backup de um pipe individual reutilizando a lógica de backup do Comando_BackupCards
5. IF o Job_BackupPipe falhar ao processar um pipe, THEN THE Job_BackupPipe SHALL atualizar o status do pipe para "failed" no Modelo_PipeBackup e registrar o erro no log

### Requisito 4: Rastreamento de Status de Backup

**User Story:** Como usuário, eu quero rastrear o status de cada backup de pipe, para que eu saiba quais pipes foram processados com sucesso e quais falharam.

#### Critérios de Aceitação

1. THE Modelo_PipeBackup SHALL armazenar para cada backup: pipe_id, pipe_name, status (pending, processing, completed, failed), mensagem de erro (quando aplicável), timestamps de início e conclusão, e um identificador de lote (batch_id)
2. WHEN um Job_BackupPipe iniciar o processamento, THEN THE Job_BackupPipe SHALL atualizar o status do pipe para "processing" no Modelo_PipeBackup
3. WHEN um Job_BackupPipe concluir com sucesso, THEN THE Job_BackupPipe SHALL atualizar o status do pipe para "completed" no Modelo_PipeBackup com o timestamp de conclusão
4. WHEN um Job_BackupPipe falhar, THEN THE Job_BackupPipe SHALL atualizar o status do pipe para "failed" no Modelo_PipeBackup com a mensagem de erro
5. WHEN o Comando_BackupAll despachar jobs no Modo_Fila, THEN THE Comando_BackupAll SHALL criar registros no Modelo_PipeBackup com status "pending" para cada pipe, todos com o mesmo batch_id

### Requisito 5: Comando de Monitoramento de Status

**User Story:** Como usuário, eu quero um comando para visualizar o status dos backups em andamento, para que eu possa acompanhar o progresso dos jobs na fila.

#### Critérios de Aceitação

1. THE Comando_BackupStatus SHALL estar disponível como `pipefy:backup-status`
2. WHEN o Comando_BackupStatus for executado sem argumentos, THEN THE Comando_BackupStatus SHALL exibir o status do lote mais recente em formato de tabela contendo: pipe_id, pipe_name, status e timestamps
3. WHEN o Comando_BackupStatus for executado, THEN THE Comando_BackupStatus SHALL exibir um resumo com: total de pipes, completados, em processamento, pendentes e com falha
4. WHEN não houver registros de backup, THEN THE Comando_BackupStatus SHALL exibir uma mensagem informativa indicando que nenhum backup foi encontrado

### Requisito 6: Tratamento de Erros e Resiliência

**User Story:** Como usuário, eu quero que o processo de backup continue mesmo quando um pipe individual falhar, para que eu obtenha o máximo de dados possível.

#### Critérios de Aceitação

1. WHEN o backup de um pipe falhar no Modo_Direto, THEN THE Comando_BackupAll SHALL registrar o erro no log, exibir um aviso no terminal e continuar com o próximo pipe
2. WHEN o Job_BackupPipe falhar na fila, THEN THE Job_BackupPipe SHALL atualizar o status para "failed" e registrar o erro, sem afetar os demais jobs
3. WHEN o Comando_BackupAll concluir no Modo_Direto com falhas parciais, THEN THE Comando_BackupAll SHALL exibir no resumo final a quantidade de pipes que falharam
