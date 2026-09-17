# Documento de Requisitos

## Introdução

Este documento especifica os requisitos para duas funcionalidades relacionadas na aplicação de backup do Pipefy:

1. **Backup Granular de Cards via Fila** — Substituir o processamento sequencial de todos os cards de um pipe por jobs individuais na fila, permitindo retry granular, processamento paralelo e rastreamento por card.
2. **Comando de Verificação de Integridade** — Um comando Artisan que verifica se todos os dados de backup de um pipe foram corretamente salvos em disco, sem necessidade de consultar a API do Pipefy.

## Glossário

- **Sistema_Backup**: O sistema de backup do Pipefy implementado nesta aplicação Laravel
- **Job_Pai**: Job de fila responsável por buscar a lista de cards de um pipe na API e despachar jobs individuais para cada card
- **Job_Card**: Job de fila responsável por fazer o backup de um único card (salvar JSON e baixar attachments)
- **PipeBackup**: Modelo que rastreia o status de backup de um pipe inteiro
- **PipeBackupCard**: Modelo que rastreia o status de backup de um card individual
- **Comando_Verificação**: Comando Artisan `pipefy:verify-backup` que verifica a integridade dos arquivos de backup em disco
- **Job_Verificação**: Job de fila responsável por verificar a integridade do backup de um único card
- **Card_JSON**: Arquivo JSON contendo os dados de um card, armazenado em `pipefy-backup/{pipe_id}/cards/{card_id}.json`
- **Attachment**: Arquivo anexo de um card, armazenado em `pipefy-backup/{pipe_id}/attachments/{card_id}/{filename}`

## Requisitos

### Requisito 1: Despacho Granular de Jobs por Card

**User Story:** Como administrador do sistema, quero que o backup de cada card seja despachado como um job individual na fila, para que falhas em cards específicos não comprometam o backup dos demais.

#### Critérios de Aceitação

1. QUANDO o Job_Pai receber um pipe_id, O Job_Pai DEVE buscar a lista de cards na API do Pipefy e despachar um Job_Card para cada card encontrado
2. QUANDO o Job_Pai despachar os Job_Card, O Job_Pai DEVE registrar o total de cards encontrados no PipeBackup e criar um registro PipeBackupCard com status "pending" para cada card
3. QUANDO nenhum card for encontrado para o pipe, O Job_Pai DEVE marcar o PipeBackup como "completed" com total_cards igual a zero
4. SE a consulta à API falhar durante a busca de cards, ENTÃO O Job_Pai DEVE marcar o PipeBackup como "failed" e registrar a mensagem de erro

### Requisito 2: Backup Individual de Card

**User Story:** Como administrador do sistema, quero que cada job de card processe independentemente o salvamento do JSON e download de attachments, para que o processamento seja isolado e paralelizável.

#### Critérios de Aceitação

1. QUANDO o Job_Card for executado, O Job_Card DEVE salvar os dados do card como JSON no caminho `pipefy-backup/{pipe_id}/cards/{card_id}.json`
2. QUANDO o Job_Card for executado, O Job_Card DEVE buscar a lista de attachments do card na API e baixar cada arquivo para `pipefy-backup/{pipe_id}/attachments/{card_id}/{filename}`
3. QUANDO o Job_Card concluir com sucesso, O Job_Card DEVE atualizar o registro PipeBackupCard correspondente com status "completed" e as contagens de attachments
4. SE o download de um attachment falhar, ENTÃO O Job_Card DEVE registrar o erro no PipeBackupError, incrementar a contagem de erros no PipeBackupCard e continuar processando os demais attachments
5. SE a busca de attachments na API falhar, ENTÃO O Job_Card DEVE salvar o card JSON sem attachments, registrar o erro e marcar o PipeBackupCard com status "completed_with_errors"
6. SE o salvamento do card JSON falhar, ENTÃO O Job_Card DEVE marcar o PipeBackupCard como "failed" e registrar a mensagem de erro

### Requisito 3: Rastreamento de Status por Card

**User Story:** Como administrador do sistema, quero rastrear o status de backup de cada card individualmente, para ter visibilidade granular do progresso e identificar rapidamente quais cards falharam.

#### Critérios de Aceitação

1. O Sistema_Backup DEVE manter uma tabela `pipe_backup_cards` com os campos: pipe_backup_id, card_id, card_title, status, attachments_count, errors_count, error_message, started_at, completed_at
2. QUANDO o status de um PipeBackupCard mudar, O Sistema_Backup DEVE recalcular as contagens agregadas no PipeBackup pai (cards_count, attachments_count, errors_count)
3. QUANDO todos os PipeBackupCard de um PipeBackup estiverem com status "completed" ou "completed_with_errors", O Sistema_Backup DEVE marcar o PipeBackup como "completed"
4. QUANDO pelo menos um PipeBackupCard tiver status "failed" e nenhum estiver "pending" ou "processing", O Sistema_Backup DEVE marcar o PipeBackup como "completed_with_errors"

### Requisito 4: Retry Granular de Cards com Falha

**User Story:** Como administrador do sistema, quero reprocessar apenas os cards que falharam, para economizar tempo e chamadas à API ao não reprocessar cards já concluídos com sucesso.

#### Critérios de Aceitação

1. QUANDO o retry for solicitado para um PipeBackup, O Sistema_Backup DEVE despachar Job_Card apenas para os PipeBackupCard com status "failed"
2. QUANDO o retry for solicitado, O Sistema_Backup DEVE resetar o status dos PipeBackupCard com falha para "pending" e limpar a mensagem de erro
3. QUANDO o retry for solicitado, O Sistema_Backup DEVE atualizar o status do PipeBackup para "processing"
4. QUANDO não houver PipeBackupCard com falha, O Sistema_Backup DEVE informar que não há cards para reprocessar

### Requisito 5: Verificação de Integridade do Backup

**User Story:** Como administrador do sistema, quero verificar se todos os arquivos de backup de um pipe estão íntegros em disco, para garantir que o backup foi concluído corretamente sem precisar consultar a API.

#### Critérios de Aceitação

1. QUANDO o Comando_Verificação for executado com um pipe_id, O Comando_Verificação DEVE despachar um Job_Verificação para cada card listado no arquivo `index.json` do pipe
2. QUANDO o Job_Verificação verificar um card, O Job_Verificação DEVE confirmar que o Card_JSON existe e contém dados válidos (JSON não vazio e decodificável)
3. QUANDO o Job_Verificação verificar um card, O Job_Verificação DEVE confirmar que cada attachment referenciado no Card_JSON possui um arquivo correspondente em disco
4. QUANDO o Job_Verificação encontrar um Card_JSON ausente ou inválido, O Job_Verificação DEVE registrar o card como falha de verificação com uma mensagem descritiva
5. QUANDO o Job_Verificação encontrar um attachment ausente em disco, O Job_Verificação DEVE registrar o attachment como falha de verificação com o nome do arquivo esperado
6. QUANDO todos os Job_Verificação concluírem, O Comando_Verificação DEVE exibir um resumo com total de cards verificados, cards íntegros e cards com problemas

### Requisito 6: Atualização da Interface de Status

**User Story:** Como administrador do sistema, quero visualizar o progresso do backup no nível de cards individuais na página de status, para acompanhar o andamento detalhado do processo.

#### Critérios de Aceitação

1. QUANDO a página de status for consultada, O Sistema_Backup DEVE retornar as contagens agregadas de PipeBackupCard por status (pending, processing, completed, completed_with_errors, failed) para cada PipeBackup
2. QUANDO a API de status for consultada, O Sistema_Backup DEVE incluir no JSON de resposta o resumo de cards por status para cada pipe do batch
