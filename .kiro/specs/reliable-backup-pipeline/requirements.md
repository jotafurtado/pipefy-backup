# Documento de Requisitos

## Introdução

O pipeline de backup do Pipefy apresenta problemas críticos de confiabilidade que causam falhas em backups de pipes com grande volume de cards. Os principais problemas são: timeout de jobs na fila por configuração inadequada do `retry_after`, cards órfãos presos em status "processing" sem mecanismo de recuperação, coluna `current_step` com tamanho insuficiente, e falta de monitoramento ativo granular. Este documento define os requisitos para corrigir definitivamente esses problemas e melhorar o acompanhamento em tempo real do processo de backup.

## Glossário

- **Sistema_Backup**: O conjunto de jobs, modelos e comandos que realizam o backup dos pipes e cards do Pipefy
- **BackupPipeJob**: Job do Laravel responsável por buscar cards de um pipe na API e despachar BackupCardJob para cada card
- **BackupCardJob**: Job do Laravel responsável por processar o backup de um card individual (JSON + attachments)
- **PipeBackup**: Modelo que rastreia o status de backup de um pipe inteiro
- **PipeBackupCard**: Modelo que rastreia o status de backup de um card individual
- **Card_Órfão**: Um PipeBackupCard que permanece em status "processing" por tempo excessivo, indicando que seu job foi perdido ou falhou silenciosamente
- **Fila_Database**: O driver de fila do Laravel usando banco de dados, configurado via `config/queue.php`
- **Dashboard**: Página web na raiz do projeto que exibe o status do backup em tempo real
- **Batch**: Grupo de backups de pipes iniciados juntos, identificados por um batch_id único

## Requisitos

### Requisito 1: Configuração de Timeout da Fila

**User Story:** Como administrador do sistema, quero que a configuração de timeout da fila seja compatível com o tempo real de execução dos jobs, para que jobs de pipes grandes não sejam marcados como abandonados prematuramente.

#### Critérios de Aceitação

1. QUANDO o BackupPipeJob for executado para um pipe com muitos cards, O Sistema_Backup DEVE permitir que o job execute por até 60 minutos sem ser considerado abandonado pela fila
2. QUANDO a Fila_Database processar jobs, O Sistema_Backup DEVE utilizar um valor de `retry_after` suficiente para acomodar o tempo de paginação da API do Pipefy para pipes grandes
3. QUANDO o BackupPipeJob exceder o tempo máximo permitido, O Sistema_Backup DEVE marcar o PipeBackup como "failed" com uma mensagem de erro descritiva

### Requisito 2: Detecção e Recuperação de Cards Órfãos

**User Story:** Como administrador do sistema, quero que o sistema detecte e recupere cards presos em status "processing", para que backups não fiquem permanentemente travados.

#### Critérios de Aceitação

1. QUANDO um comando de recuperação for executado, O Sistema_Backup DEVE identificar todos os PipeBackupCard com status "processing" há mais de um tempo limite configurável
2. QUANDO um Card_Órfão for detectado, O Sistema_Backup DEVE marcar o PipeBackupCard como "failed" com uma mensagem indicando que o job foi considerado órfão
3. QUANDO cards órfãos forem detectados e marcados como falha, O Sistema_Backup DEVE recalcular o status agregado do PipeBackup pai
4. QUANDO o comando de recuperação for executado e não houver cards órfãos, O Sistema_Backup DEVE informar que nenhum card órfão foi encontrado

### Requisito 3: Correção do Schema do Banco de Dados

**User Story:** Como administrador do sistema, quero que a coluna `current_step` suporte textos descritivos de progresso sem truncamento, para que as mensagens de status sejam exibidas corretamente.

#### Critérios de Aceitação

1. O Sistema_Backup DEVE armazenar mensagens de progresso na coluna `current_step` com até 255 caracteres sem truncamento
2. QUANDO uma mensagem de progresso for gravada na coluna `current_step`, O Sistema_Backup DEVE persistir o texto completo sem erro de banco de dados

### Requisito 4: Monitoramento Ativo e Granular de Progresso

**User Story:** Como administrador do sistema, quero acompanhar ativamente o progresso detalhado de cada etapa do backup, para saber exatamente o que está acontecendo em tempo real.

#### Critérios de Aceitação

1. ENQUANTO o BackupPipeJob estiver buscando cards na API, O Sistema_Backup DEVE atualizar o campo `current_step` do PipeBackup com o número de cards encontrados a cada página processada
2. ENQUANTO cards estiverem sendo processados, O Dashboard DEVE exibir a contagem de cards por status (pendente, processando, concluído, com erros, falha) para cada pipe
3. QUANDO o Dashboard atualizar os dados via polling, O Dashboard DEVE refletir as estatísticas mais recentes do banco de dados com intervalo configurável
4. QUANDO um pipe estiver em processamento, O Dashboard DEVE exibir uma barra de progresso baseada na proporção de cards concluídos em relação ao total

### Requisito 5: Recuperação de Pipes Travados

**User Story:** Como administrador do sistema, quero forçar a conclusão de pipes que ficaram travados em "processing", para que o sistema não fique em estado inconsistente indefinidamente.

#### Critérios de Aceitação

1. QUANDO um comando de limpeza for executado, O Sistema_Backup DEVE identificar PipeBackups com status "processing" que não tiveram atualização em seus cards por mais de um tempo limite configurável
2. QUANDO um PipeBackup travado for detectado, O Sistema_Backup DEVE marcar todos os PipeBackupCard em status "processing" ou "pending" como "failed" com mensagem descritiva
3. QUANDO um PipeBackup travado for finalizado forçadamente, O Sistema_Backup DEVE recalcular o status do PipeBackup para "completed_with_errors" ou "failed" conforme o resultado dos cards
4. QUANDO o comando de limpeza for executado e não houver pipes travados, O Sistema_Backup DEVE informar que nenhum pipe travado foi encontrado

### Requisito 6: Retry Inteligente de Pipes com Falha

**User Story:** Como administrador do sistema, quero que o retry de um pipe com falha reprocesse apenas os cards que falharam, para economizar tempo e chamadas à API.

#### Critérios de Aceitação

1. QUANDO o retry for solicitado para um PipeBackup que já possui cards com status "completed", O Sistema_Backup DEVE redespachar BackupCardJob apenas para os PipeBackupCard com status "failed"
2. QUANDO o retry for solicitado para um PipeBackup com status "failed" que não possui nenhum PipeBackupCard, O Sistema_Backup DEVE redespachar o BackupPipeJob para buscar os cards novamente na API
3. QUANDO o retry redespachar cards individuais, O Sistema_Backup DEVE resetar o status dos PipeBackupCard com falha para "pending" e limpar a mensagem de erro
4. QUANDO o retry for solicitado e não houver itens com falha, O Sistema_Backup DEVE informar que não há itens para reprocessar

### Requisito 7: Melhorias na Interface do Dashboard

**User Story:** Como administrador do sistema, quero que o dashboard exiba informações mais detalhadas e ações de recuperação, para que eu possa gerenciar o backup de forma autônoma pela interface web.

#### Critérios de Aceitação

1. QUANDO houver pipes com falha ou travados, O Dashboard DEVE exibir um botão de retry que reprocessa apenas os itens com falha
2. QUANDO houver pipes em status "processing" por tempo excessivo, O Dashboard DEVE exibir um indicador visual de alerta
3. QUANDO o Dashboard exibir o progresso de um pipe, O Dashboard DEVE mostrar o tempo decorrido desde o início do processamento
4. QUANDO o retry for acionado pelo Dashboard, O Dashboard DEVE exibir feedback imediato da ação e atualizar o status automaticamente
