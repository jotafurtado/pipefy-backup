# Plano de Implementação: Pipefy Cards Backup

## Visão Geral

Implementação incremental do backup de cards do Pipefy. Cada tarefa constrói sobre a anterior: primeiro os novos métodos no serviço existente, depois o comando Artisan com persistência e download de attachments.

## Tarefas

- [x]   1. Estender PipefyService com métodos de cards e attachments
    - [x] 1.1 Implementar método `getCards(int $pipeId): array` no `PipefyService`
        - Adicionar query GraphQL `allCards` com todos os campos: id, title, assignees, comments, comments_count, current_phase, done, due_date, fields, labels, phases_history, url
        - Implementar paginação automática cursor-based: loop enquanto `hasNextPage` for true, usando `endCursor` como `after`
        - Limitar a 50 cards por requisição (`first: 50`)
        - Retornar array flat com todos os cards agregados de todas as páginas
        - _Requisitos: 1.1, 1.2, 1.3, 1.4, 1.5, 2.1, 2.2, 2.3_
    - [x] 1.2 Implementar método `getCardAttachments(int $cardId): array` no `PipefyService`
        - Adicionar query GraphQL para buscar attachments de um card: id, filename, url, createdAt, path
        - Retornar array de attachments ou array vazio se não houver
        - _Requisitos: 3.1, 3.2, 3.3_
    - [x] 1.3 Escrever teste de propriedade para agregação de paginação
        - **Propriedade 1: Agregação completa de paginação**
        - Gerar N cards aleatórios (entre 0 e 200), distribuir em páginas de 50, mockar respostas HTTP sequenciais, verificar que getCards retorna exatamente N cards
        - **Valida: Requisitos 1.1, 1.2**
    - [x] 1.4 Escrever teste de propriedade para limite de página
        - **Propriedade 2: Limite de página nas requisições**
        - Interceptar requisições HTTP feitas por getCards e verificar que todas contêm `first` com valor 50
        - **Valida: Requisitos 1.3**
    - [x] 1.5 Escrever teste de propriedade para completude dos dados do card
        - **Propriedade 3: Completude dos dados do card**
        - Gerar cards com campos aleatórios (incluindo fields e phases_history), mockar API, verificar que todos os campos estão presentes e com valores equivalentes no retorno
        - **Valida: Requisitos 2.1, 2.2, 2.3**
    - [x] 1.6 Escrever teste de propriedade para completude dos dados de attachment
        - **Propriedade 4: Completude dos dados de attachment**
        - Gerar attachments com dados aleatórios, mockar API, verificar que id, filename, url, createdAt e path estão presentes no retorno
        - **Valida: Requisitos 3.1**
    - [x] 1.7 Escrever testes unitários para edge cases dos novos métodos
        - Testar pipe com zero cards retorna array vazio (Requisito 1.4)
        - Testar card com zero attachments retorna array vazio (Requisito 3.2)
        - Testar erro de conexão durante paginação lança PipefyApiException (Requisito 1.5)
        - Testar erro ao consultar attachments lança PipefyApiException (Requisito 3.3)
        - _Requisitos: 1.4, 1.5, 3.2, 3.3_

- [x]   2. Checkpoint - Verificar testes do serviço
    - Garantir que todos os testes passam, perguntar ao usuário se houver dúvidas.

- [x]   3. Criar comando Artisan de backup com persistência JSON
    - [x] 3.1 Criar `app/Console/Commands/PipefyBackupCardsCommand.php`
        - Assinatura: `pipefy:backup-cards {pipe_id : ID do pipe para backup}`
        - Injetar `PipefyService` via método `handle()`
        - Buscar todos os cards via `getCards($pipeId)`
        - Exibir total de cards encontrados
        - Iterar sobre cada card com barra de progresso
        - Para cada card: consultar attachments, salvar JSON do card, fazer download dos attachments
        - Gerar `index.json` com resumo do backup
        - Exibir resumo final: cards salvos, attachments baixados, erros, caminho do backup
        - Capturar `PipefyApiException` para erros fatais, retornar `Command::FAILURE`
        - _Requisitos: 4.1, 4.2, 4.3, 4.4, 4.5, 4.6_
    - [x] 3.2 Implementar persistência de cards em JSON
        - Salvar cada card como `pipefy-backup/{pipe_id}/cards/{card_id}.json` usando `Storage::disk('local')`
        - Usar `JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE` para formatação legível
        - Gerar `index.json` com pipe_id, backup_date, total_cards, total_attachments_downloaded, errors_count e lista de cards (id + title)
        - _Requisitos: 5.1, 5.2, 5.3_
    - [x] 3.3 Implementar download de attachments
        - Implementar método privado `downloadAttachment(string $url, string $storagePath): void` usando HTTP Client com sink para streaming
        - Implementar método privado `resolveFilename(string $directory, string $filename): string` para tratar nomes duplicados com sufixo numérico
        - Salvar attachments em `pipefy-backup/{pipe_id}/attachments/{card_id}/{filename}`
        - _Requisitos: 6.1, 6.3, 6.4_
    - [x] 3.4 Implementar tratamento de erros parciais
        - Capturar exceções no download de attachments individuais: registrar no log, exibir aviso, continuar
        - Capturar exceções na consulta de attachments por card: registrar no log, salvar card sem attachments, continuar
        - Manter contadores de erros e exibir no resumo final
        - _Requisitos: 6.2, 7.1, 7.2, 7.3, 7.4_
    - [x] 3.5 Escrever teste de propriedade para round-trip JSON
        - **Propriedade 5: Round-trip de serialização JSON dos cards**
        - Gerar arrays de card com dados aleatórios, json_encode com JSON_PRETTY_PRINT, json_decode, verificar equivalência
        - **Valida: Requisitos 5.4**
    - [x] 3.6 Escrever teste de propriedade para estrutura de caminhos
        - **Propriedade 6: Estrutura de caminhos no storage**
        - Gerar pipe_id e card_id aleatórios, executar backup com mocks, verificar que os arquivos existem nos caminhos corretos no Storage fake
        - **Valida: Requisitos 5.1, 6.1, 6.3**
    - [x] 3.7 Escrever teste de propriedade para completude do índice
        - **Propriedade 7: Completude do arquivo de índice**
        - Gerar conjunto de cards aleatórios, executar backup com mocks, verificar que index.json contém exatamente os mesmos IDs e títulos
        - **Valida: Requisitos 5.3**
    - [x] 3.8 Escrever teste de propriedade para resolução de nomes duplicados
        - **Propriedade 8: Resolução de nomes duplicados de attachment**
        - Gerar lista de filenames com duplicatas, chamar resolveFilename sequencialmente, verificar que todos os nomes resultantes são únicos
        - **Valida: Requisitos 6.4**
    - [x] 3.9 Escrever testes unitários para o comando Artisan
        - Testar fluxo completo com mock do PipefyService e Storage fake
        - Testar comando com pipe_id inválido retorna FAILURE (Requisito 4.6/7.4)
        - Testar que output contém total de cards encontrados (Requisito 4.3)
        - Testar que output contém resumo final com totais (Requisito 4.5)
        - Testar que comando continua após falha de download de attachment (Requisito 6.2/7.1)
        - Testar que comando salva card mesmo quando consulta de attachments falha (Requisito 7.2)
        - Testar que output contém contagem de erros (Requisito 7.3)
        - Testar que JSON é salvo com pretty-print (Requisito 5.2)
        - _Requisitos: 4.1, 4.2, 4.3, 4.5, 4.6, 5.2, 6.2, 7.1, 7.2, 7.3, 7.4_

- [x]   4. Checkpoint final - Verificar todos os testes
    - Garantir que todos os testes passam, perguntar ao usuário se deseja rodar a suite completa.

## Notas

- Tarefas marcadas com `*` são opcionais e podem ser puladas para um MVP mais rápido
- Cada tarefa referencia requisitos específicos para rastreabilidade
- Checkpoints garantem validação incremental
- Testes de propriedade validam propriedades universais de corretude (mínimo 100 iterações com Faker)
- Testes unitários validam exemplos específicos e edge cases
- O `PipefyService` já existe e está registrado como singleton — apenas adicionar os novos métodos
