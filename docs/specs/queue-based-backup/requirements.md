# Documento de Requisitos

## Introdução

O sistema atual de backup do Pipefy executa todas as operações de forma síncrona através de comandos Artisan. Isso torna o processo lento e vulnerável a interrupções — qualquer falha no meio do caminho exige recomeçar tudo do zero. Este documento define os requisitos para refatorar o sistema para utilizar filas do Laravel (queues), tornando-o resiliente a interrupções e capaz de retomar de onde parou. Além disso, será criada uma página web na raiz do projeto para monitorar o progresso do processamento da fila em tempo real.

## Glossário

- **Sistema_de_Backup**: O conjunto de comandos, jobs e modelos que realizam o backup dos pipes e cards do Pipefy
- **Fila**: O sistema de filas do Laravel (queue) que processa jobs de forma assíncrona
- **Batch**: Um grupo de backups de pipes iniciados juntos, identificados por um batch_id único
- **PipeBackup**: Registro no banco de dados que rastreia o status do backup de um pipe individual
- **BackupPipeJob**: Job do Laravel que executa o backup de um pipe específico na fila
- **Página_de_Monitoramento**: Página web acessível na raiz do projeto que exibe o status do processamento da fila
- **Card**: Unidade de trabalho dentro de um pipe no Pipefy, contendo dados e attachments

## Requisitos

### Requisito 1: Despacho de Backups para Fila

**User Story:** Como administrador do sistema, quero que o comando de backup despache jobs para a fila do Laravel, para que o processamento seja assíncrono e não bloqueie a execução.

#### Critérios de Aceitação

1. QUANDO o comando `pipefy:backup-all` for executado, O Sistema_de_Backup DEVE despachar um BackupPipeJob para cada pipe encontrado na organização
2. QUANDO um BackupPipeJob for despachado, O Sistema_de_Backup DEVE criar um registro PipeBackup com status "pending" antes do despacho
3. QUANDO o BackupPipeJob iniciar o processamento, O Sistema_de_Backup DEVE atualizar o status do PipeBackup para "processing" e registrar o horário de início

### Requisito 2: Resiliência a Interrupções e Retentativas

**User Story:** Como administrador do sistema, quero que o sistema de backup seja resiliente a interrupções, para que eu não precise recomeçar todo o processo caso algo falhe no meio do caminho.

#### Critérios de Aceitação

1. QUANDO um BackupPipeJob falhar, O Sistema_de_Backup DEVE registrar o erro no PipeBackup e marcar o status como "failed"
2. QUANDO um BackupPipeJob falhar, O Sistema_de_Backup DEVE permitir retentativas automáticas configuráveis antes de marcar como falha definitiva
3. QUANDO o comando `pipefy:backup-all` for executado e existirem PipeBackups com status "pending" ou "failed" do mesmo batch, O Sistema_de_Backup DEVE oferecer a opção de reprocessar apenas os pipes pendentes ou com falha
4. SE o worker da fila for interrompido durante o processamento, ENTÃO O Sistema_de_Backup DEVE manter os backups já concluídos intactos e permitir reprocessar apenas os incompletos

### Requisito 3: Granularidade do Processamento de Cards

**User Story:** Como administrador do sistema, quero que o backup de cards de um pipe seja dividido em jobs menores, para que falhas em cards individuais não comprometam o backup inteiro do pipe.

#### Critérios de Aceitação

1. QUANDO o BackupPipeJob processar um pipe, O Sistema_de_Backup DEVE despachar jobs individuais para o backup de cada card ou lote de cards
2. QUANDO um job de backup de card falhar, O Sistema_de_Backup DEVE registrar o erro no PipeBackupError e continuar processando os demais cards
3. QUANDO todos os jobs de cards de um pipe forem concluídos, O Sistema_de_Backup DEVE atualizar o status do PipeBackup para "completed" com as estatísticas finais

### Requisito 4: Página de Monitoramento na Raiz do Projeto

**User Story:** Como administrador do sistema, quero uma página web na raiz do projeto que mostre o status do processamento da fila, para que eu possa acompanhar o progresso dos backups.

#### Critérios de Aceitação

1. QUANDO um usuário acessar a URL raiz do projeto, A Página_de_Monitoramento DEVE exibir o status do batch de backup mais recente
2. QUANDO a Página_de_Monitoramento for carregada, A Página_de_Monitoramento DEVE exibir para cada pipe: nome, status, quantidade de cards, quantidade de attachments e quantidade de erros
3. QUANDO a Página_de_Monitoramento for carregada, A Página_de_Monitoramento DEVE exibir um resumo geral com totais de pipes completados, em processamento, pendentes e com falha
4. QUANDO houver pipes com status "failed", A Página_de_Monitoramento DEVE exibir a mensagem de erro associada
5. A Página_de_Monitoramento DEVE atualizar os dados automaticamente sem necessidade de recarregar a página manualmente
6. QUANDO houver pipes com falha, A Página_de_Monitoramento DEVE oferecer um botão para reprocessar os pipes com falha

### Requisito 5: Progresso em Tempo Real

**User Story:** Como administrador do sistema, quero ver o progresso do backup em tempo real, para que eu saiba exatamente em que ponto o processamento está.

#### Critérios de Aceitação

1. ENQUANTO um batch estiver sendo processado, A Página_de_Monitoramento DEVE exibir uma barra de progresso geral baseada na proporção de pipes concluídos
2. ENQUANTO um pipe estiver sendo processado, O Sistema_de_Backup DEVE atualizar as estatísticas (cards_count, attachments_count, errors_count) incrementalmente no banco de dados
3. QUANDO a Página_de_Monitoramento atualizar os dados, A Página_de_Monitoramento DEVE refletir as estatísticas mais recentes do banco de dados

### Requisito 6: Comando de Reprocessamento

**User Story:** Como administrador do sistema, quero poder reprocessar apenas os pipes que falharam, para que eu não precise refazer o backup de pipes que já foram concluídos com sucesso.

#### Critérios de Aceitação

1. QUANDO o comando `pipefy:backup-all --retry` for executado, O Sistema_de_Backup DEVE identificar o batch mais recente e redespachar jobs apenas para pipes com status "failed" ou "pending"
2. QUANDO um pipe for redespachado para reprocessamento, O Sistema_de_Backup DEVE resetar o status do PipeBackup para "pending" e limpar a mensagem de erro anterior
3. SE não houver pipes com falha ou pendentes no batch mais recente, ENTÃO O Sistema_de_Backup DEVE informar que todos os pipes já foram processados com sucesso
