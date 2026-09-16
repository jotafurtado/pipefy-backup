# Plano de Implementação: Pipefy Backup

## Visão Geral

Implementação incremental da conexão com a API GraphQL do Pipefy e listagem de pipes. Cada tarefa constrói sobre a anterior, começando pela configuração e exceção, passando pelo serviço, e finalizando com o comando Artisan.

## Tarefas

- [x]   1. Configuração e exceção customizada
    - [x] 1.1 Adicionar configuração do Pipefy em `config/services.php` e `.env.example`
        - Adicionar chave `pipefy` com `client_id`, `client_secret`, `token_url` e `endpoint` em `config/services.php`
        - Adicionar `PIPEFY_CLIENT_ID=`, `PIPEFY_CLIENT_SECRET=`, `PIPEFY_TOKEN_URL=` e `PIPEFY_API_ENDPOINT=` no `.env.example`
        - _Requisitos: 1.1_
    - [x] 1.2 Criar `app/Exceptions/PipefyApiException.php`
        - Criar classe com métodos estáticos: `missingToken()`, `connectionError()`, `invalidResponse()`, `graphqlErrors()`, `httpError()`
        - Cada método retorna uma instância com mensagem descritiva em português
        - _Requisitos: 1.2, 2.3, 2.4, 2.5, 6.2_

- [-] 2. Serviço de comunicação com a API
    - [x] 2.1 Criar `app/Services/PipefyService.php`
        - Implementar construtor com `$token` e `$endpoint`
        - Implementar método `query(string $query, array $variables = []): array` usando Laravel HTTP Client
        - Implementar método `getOrganizations(): array`
        - Implementar método `getPipes(int $organizationId): array`
        - Tratar erros: JSON inválido, erros GraphQL, status HTTP não-200, falha de conexão
        - _Requisitos: 1.3, 2.1, 2.2, 2.3, 2.4, 2.5, 3.1, 3.2, 4.1, 4.2, 4.3, 6.1, 6.2_
    - [x] 2.2 Registrar `PipefyService` como singleton no `AppServiceProvider`
        - Ler client_id, client_secret, token_url e endpoint de `config('services.pipefy')`
        - Lançar `PipefyApiException::missingToken()` se client_id ou client_secret estiverem vazios
        - _Requisitos: 1.1, 1.2_
    - [ ]\* 2.3 Escrever teste de propriedade para formato das requisições
        - **Propriedade 1: Formato das requisições à API**
        - **Valida: Requisitos 1.3, 2.1**
    - [ ]\* 2.4 Escrever teste de propriedade para parsing de respostas
        - **Propriedade 2: Parsing de respostas bem-sucedidas**
        - **Valida: Requisitos 2.2**
    - [ ]\* 2.5 Escrever teste de propriedade para erros GraphQL
        - **Propriedade 3: Propagação de erros GraphQL**
        - **Valida: Requisitos 2.3**
    - [ ]\* 2.6 Escrever teste de propriedade para erros HTTP
        - **Propriedade 4: Propagação de erros HTTP**
        - **Valida: Requisitos 2.5**
    - [ ]\* 2.7 Escrever teste de propriedade para extração de entidades
        - **Propriedade 5: Extração de entidades da resposta**
        - **Valida: Requisitos 3.1, 4.1**
    - [ ]\* 2.8 Escrever teste de propriedade para round-trip JSON
        - **Propriedade 6: Round-trip de serialização JSON**
        - **Valida: Requisitos 6.3**
    - [ ]\* 2.9 Escrever testes unitários para edge cases do PipefyService
        - Testar token ausente lança exceção (Requisito 1.2)
        - Testar JSON inválido na resposta (Requisito 6.2)
        - Testar erro de conexão/timeout (Requisito 2.4)
        - Testar lista vazia de organizações (Requisito 3.2)
        - Testar lista vazia de pipes (Requisito 4.3)
        - _Requisitos: 1.2, 2.4, 3.2, 4.3, 6.2_

- [x]   3. Checkpoint - Verificar testes do serviço
    - Garantir que todos os testes passam, perguntar ao usuário se houver dúvidas.

- [-] 4. Comando Artisan para listar pipes
    - [x] 4.1 Criar `app/Console/Commands/PipefyPipesCommand.php`
        - Assinatura: `pipefy:pipes`
        - Injetar `PipefyService` via método `handle()`
        - Buscar organizações e apresentar seleção interativa com `Laravel\Prompts\select()`
        - Buscar pipes da organização selecionada e exibir em tabela
        - Exibir mensagem quando nenhum pipe for encontrado
        - Capturar `PipefyApiException` e exibir erro amigável, retornando `Command::FAILURE`
        - _Requisitos: 5.1, 5.2, 5.3, 5.4, 5.5_
    - [ ]\* 4.2 Escrever testes unitários para o comando Artisan
        - Testar fluxo completo com mock do PipefyService
        - Testar exibição de tabela com pipes
        - Testar mensagem de nenhum pipe encontrado
        - Testar tratamento de erro de conexão
        - _Requisitos: 5.1, 5.2, 5.3, 5.4, 5.5_

- [x]   5. Checkpoint final - Verificar todos os testes
    - Garantir que todos os testes passam, perguntar ao usuário se deseja rodar a suite completa.

## Notas

- Tarefas marcadas com `*` são opcionais e podem ser puladas para um MVP mais rápido
- Cada tarefa referencia requisitos específicos para rastreabilidade
- Checkpoints garantem validação incremental
- Testes de propriedade validam propriedades universais de corretude
- Testes unitários validam exemplos específicos e edge cases
