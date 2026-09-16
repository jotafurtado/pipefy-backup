# Documento de Requisitos

## Introdução

Este documento descreve os requisitos para a funcionalidade de backup de cards do Pipefy. O objetivo é criar um comando Artisan que faça o download de todos os cards de um pipe específico, salvando os dados em JSON e os arquivos anexos (attachments) localmente no storage da aplicação. A funcionalidade estende o `PipefyService` existente com novos métodos para consulta de cards e attachments via API GraphQL.

## Glossário

- **Serviço_Pipefy**: Classe de serviço existente (`PipefyService`) responsável pela comunicação com a API GraphQL do Pipefy
- **API_Pipefy**: API GraphQL do Pipefy acessível em `https://api.pipefy.com/graphql`
- **Card**: Unidade de trabalho dentro de um Pipe no Pipefy, contendo título, campos, fases, comentários, labels e anexos
- **Pipe**: Processo ou fluxo de trabalho dentro de uma Organização no Pipefy, identificado por um ID numérico
- **Attachment**: Arquivo anexado a um Card, com URL temporária de download (expira em ~15 minutos)
- **Comando_BackupCards**: Comando Artisan `pipefy:backup-cards` que executa o backup de cards de um pipe
- **Diretório_Backup**: Diretório base no storage local onde os dados do backup são armazenados (`pipefy-backup/{pipe_id}/`)

## Requisitos

### Requisito 1: Consulta de Cards com Paginação

**User Story:** Como desenvolvedor, eu quero consultar todos os cards de um pipe via API GraphQL com paginação, para que eu possa obter a lista completa de cards independente da quantidade.

#### Critérios de Aceitação

1. WHEN o Serviço_Pipefy receber um ID de pipe válido, THEN THE Serviço_Pipefy SHALL executar a query `allCards` e retornar uma coleção contendo todos os cards do pipe
2. WHILE a resposta da API_Pipefy indicar `hasNextPage` como verdadeiro, THE Serviço_Pipefy SHALL continuar buscando a próxima página usando o cursor `endCursor` como parâmetro `after`
3. THE Serviço_Pipefy SHALL solicitar no máximo 50 cards por requisição, respeitando o limite da API_Pipefy
4. WHEN a API_Pipefy retornar zero cards para um pipe, THEN THE Serviço_Pipefy SHALL retornar uma coleção vazia
5. WHEN ocorrer um erro de conexão durante a paginação, THEN THE Serviço_Pipefy SHALL lançar uma exceção descritiva com detalhes do erro

### Requisito 2: Dados do Card

**User Story:** Como desenvolvedor, eu quero que cada card contenha todos os campos relevantes, para que o backup seja completo e útil.

#### Critérios de Aceitação

1. THE Serviço_Pipefy SHALL retornar para cada card os seguintes campos: id, title, assignees, comments, comments_count, current_phase, done, due_date, fields, labels, phases_history e url
2. WHEN um card possuir campos customizados (fields), THEN THE Serviço_Pipefy SHALL incluir o nome e valor de cada campo na resposta
3. WHEN um card possuir histórico de fases (phases_history), THEN THE Serviço_Pipefy SHALL incluir o nome da fase, data de entrada e data de saída

### Requisito 3: Consulta de Attachments de um Card

**User Story:** Como desenvolvedor, eu quero consultar os attachments de um card específico, para que eu possa fazer o download dos arquivos anexados.

#### Critérios de Aceitação

1. WHEN o Serviço_Pipefy receber um ID de card válido, THEN THE Serviço_Pipefy SHALL executar a query de attachments e retornar uma coleção contendo id, filename, url, createdAt e path de cada attachment
2. WHEN um card possuir zero attachments, THEN THE Serviço_Pipefy SHALL retornar uma coleção vazia
3. WHEN a API_Pipefy retornar erro ao consultar attachments de um card, THEN THE Serviço_Pipefy SHALL lançar uma exceção descritiva

### Requisito 4: Comando Artisan de Backup

**User Story:** Como usuário, eu quero um comando Artisan para fazer backup dos cards de um pipe, para que eu possa executar o backup diretamente no terminal.

#### Critérios de Aceitação

1. THE Comando_BackupCards SHALL estar disponível como `pipefy:backup-cards` e aceitar um argumento obrigatório `pipe_id`
2. WHEN o Comando_BackupCards for executado com um pipe_id válido, THEN THE Comando_BackupCards SHALL buscar todos os cards do pipe e salvar os dados em JSON
3. WHEN o Comando_BackupCards iniciar a execução, THEN THE Comando_BackupCards SHALL exibir o total de cards encontrados no pipe
4. WHILE o Comando_BackupCards estiver processando cards, THE Comando_BackupCards SHALL exibir uma barra de progresso indicando quantos cards foram processados do total
5. WHEN o Comando_BackupCards concluir com sucesso, THEN THE Comando_BackupCards SHALL exibir um resumo contendo: total de cards salvos, total de attachments baixados e caminho do diretório de backup
6. IF ocorrer um erro fatal (ex: pipe não encontrado, falha de autenticação), THEN THE Comando_BackupCards SHALL exibir uma mensagem de erro e retornar código de saída diferente de zero

### Requisito 5: Persistência dos Dados em JSON

**User Story:** Como usuário, eu quero que os dados dos cards sejam salvos em arquivos JSON, para que eu possa consultar e processar os dados offline.

#### Critérios de Aceitação

1. WHEN um card for processado, THEN THE Comando_BackupCards SHALL salvar os dados do card como arquivo JSON no caminho `pipefy-backup/{pipe_id}/cards/{card_id}.json` no storage local
2. THE Comando_BackupCards SHALL salvar o JSON com formatação legível (pretty-print com indentação)
3. WHEN todos os cards forem processados, THEN THE Comando_BackupCards SHALL salvar um arquivo de índice `pipefy-backup/{pipe_id}/cards/index.json` contendo a lista de todos os card IDs, títulos e timestamps do backup
4. FOR ALL cards salvos em JSON, deserializar o arquivo JSON SHALL produzir um array equivalente aos dados originais do card (propriedade de round-trip)

### Requisito 6: Download de Attachments

**User Story:** Como usuário, eu quero que os arquivos anexados aos cards sejam baixados e salvos localmente, para que eu tenha uma cópia completa dos dados do pipe.

#### Critérios de Aceitação

1. WHEN um card possuir attachments, THEN THE Comando_BackupCards SHALL fazer o download de cada arquivo e salvá-lo no caminho `pipefy-backup/{pipe_id}/attachments/{card_id}/{filename}` no storage local
2. WHEN o download de um attachment individual falhar, THEN THE Comando_BackupCards SHALL registrar o erro no log, exibir um aviso no terminal e continuar processando os demais attachments
3. WHEN um attachment for baixado com sucesso, THEN THE Comando_BackupCards SHALL preservar o nome original do arquivo (filename)
4. WHEN dois attachments do mesmo card possuírem o mesmo filename, THEN THE Comando_BackupCards SHALL adicionar um sufixo numérico ao nome do arquivo duplicado para evitar sobrescrita

### Requisito 7: Tratamento de Erros e Resiliência

**User Story:** Como usuário, eu quero que o processo de backup continue mesmo quando ocorrerem erros pontuais, para que eu obtenha o máximo de dados possível.

#### Critérios de Aceitação

1. WHEN o download de um attachment falhar, THEN THE Comando_BackupCards SHALL continuar processando os demais cards e attachments
2. WHEN a consulta de attachments de um card específico falhar, THEN THE Comando_BackupCards SHALL registrar o erro, salvar os dados do card sem attachments e continuar com o próximo card
3. WHEN o Comando_BackupCards concluir com erros parciais, THEN THE Comando_BackupCards SHALL exibir no resumo final a quantidade de erros ocorridos
4. IF o pipe_id fornecido for inválido ou inexistente, THEN THE Comando_BackupCards SHALL exibir uma mensagem de erro clara e retornar código de saída diferente de zero
