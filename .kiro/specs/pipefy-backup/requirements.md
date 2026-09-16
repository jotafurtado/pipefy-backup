# Documento de Requisitos

## Introdução

Este documento descreve os requisitos para a funcionalidade de backup do Pipefy. O objetivo é conectar à API GraphQL do Pipefy, listar organizações e pipes disponíveis, e fornecer uma interface via comando Artisan para que o usuário possa visualizar seus pipes. Esta é a fase inicial do sistema de backup, focada na conexão e listagem.

## Glossário

- **Serviço_Pipefy**: Classe de serviço responsável por toda comunicação com a API GraphQL do Pipefy
- **API_Pipefy**: API GraphQL do Pipefy acessível em `https://api.pipefy.com/graphql` via requisições POST
- **Token_API**: Token de autenticação OAuth2 Bearer utilizado para autenticar requisições na API_Pipefy
- **Organização**: Entidade no Pipefy que agrupa pipes e processos de uma empresa
- **Pipe**: Processo ou fluxo de trabalho dentro de uma Organização no Pipefy
- **Comando_ListarPipes**: Comando Artisan que permite ao usuário listar os pipes disponíveis

## Requisitos

### Requisito 1: Configuração do Token da API

**User Story:** Como desenvolvedor, eu quero configurar o token da API do Pipefy via variáveis de ambiente, para que as credenciais fiquem seguras e fora do código-fonte.

#### Critérios de Aceitação

1. THE Serviço_Pipefy SHALL ler o Token_API a partir da configuração da aplicação utilizando o arquivo `config/services.php`
2. WHEN o Token_API estiver ausente na configuração, THEN THE Serviço_Pipefy SHALL lançar uma exceção descritiva informando que o token não foi configurado
3. THE Serviço_Pipefy SHALL utilizar o Token_API no cabeçalho `Authorization` como `Bearer {token}` em todas as requisições à API_Pipefy

### Requisito 2: Conexão com a API GraphQL do Pipefy

**User Story:** Como desenvolvedor, eu quero que o sistema se conecte à API GraphQL do Pipefy, para que eu possa consultar dados das organizações e pipes.

#### Critérios de Aceitação

1. THE Serviço_Pipefy SHALL enviar requisições POST para o endpoint `https://api.pipefy.com/graphql` com o cabeçalho `Content-Type: application/json`
2. WHEN a API_Pipefy retornar uma resposta com status HTTP 200 e campo `data`, THEN THE Serviço_Pipefy SHALL retornar os dados parseados como array associativo
3. WHEN a API_Pipefy retornar uma resposta com campo `errors`, THEN THE Serviço_Pipefy SHALL lançar uma exceção contendo a mensagem de erro retornada pela API
4. WHEN a requisição à API_Pipefy falhar por timeout ou erro de rede, THEN THE Serviço_Pipefy SHALL lançar uma exceção descritiva com detalhes do erro de conexão
5. WHEN a API_Pipefy retornar um status HTTP diferente de 200, THEN THE Serviço_Pipefy SHALL lançar uma exceção contendo o código de status e a mensagem de resposta

### Requisito 3: Listagem de Organizações

**User Story:** Como usuário, eu quero listar as organizações disponíveis na minha conta Pipefy, para que eu possa identificar de qual organização desejo listar os pipes.

#### Critérios de Aceitação

1. WHEN o usuário solicitar a listagem de organizações, THEN THE Serviço_Pipefy SHALL executar a query GraphQL de organizações e retornar uma coleção contendo id e nome de cada Organização
2. WHEN a API_Pipefy retornar uma lista vazia de organizações, THEN THE Serviço_Pipefy SHALL retornar uma coleção vazia

### Requisito 4: Listagem de Pipes de uma Organização

**User Story:** Como usuário, eu quero listar os pipes de uma organização específica, para que eu possa ver quais processos estão disponíveis para backup.

#### Critérios de Aceitação

1. WHEN o usuário fornecer um ID de Organização válido, THEN THE Serviço_Pipefy SHALL executar a query GraphQL e retornar uma coleção contendo id e nome de cada Pipe da Organização
2. WHEN o ID de Organização fornecido for inválido ou inexistente, THEN THE Serviço_Pipefy SHALL propagar o erro retornado pela API_Pipefy como exceção
3. WHEN a Organização fornecida possuir zero pipes, THEN THE Serviço_Pipefy SHALL retornar uma coleção vazia

### Requisito 5: Comando Artisan para Listar Pipes

**User Story:** Como usuário, eu quero um comando Artisan para listar os pipes disponíveis, para que eu possa visualizar os processos diretamente no terminal.

#### Critérios de Aceitação

1. THE Comando_ListarPipes SHALL estar disponível como `pipefy:pipes`
2. WHEN o Comando_ListarPipes for executado sem argumentos, THEN THE Comando_ListarPipes SHALL listar as organizações disponíveis e solicitar ao usuário que selecione uma
3. WHEN o usuário selecionar uma Organização, THEN THE Comando_ListarPipes SHALL exibir os pipes da Organização selecionada em formato de tabela contendo id e nome
4. WHEN a Organização selecionada possuir zero pipes, THEN THE Comando_ListarPipes SHALL exibir uma mensagem informando que nenhum pipe foi encontrado
5. IF ocorrer um erro de conexão ou autenticação durante a execução, THEN THE Comando_ListarPipes SHALL exibir uma mensagem de erro clara e retornar código de saída diferente de zero

### Requisito 6: Serialização de Respostas da API

**User Story:** Como desenvolvedor, eu quero que as respostas da API sejam parseadas de forma confiável, para que os dados possam ser utilizados corretamente pela aplicação.

#### Critérios de Aceitação

1. WHEN a API_Pipefy retornar uma resposta JSON válida, THEN THE Serviço_Pipefy SHALL deserializar a resposta em um array associativo PHP
2. WHEN a API_Pipefy retornar uma resposta com JSON inválido, THEN THE Serviço_Pipefy SHALL lançar uma exceção descritiva informando falha na deserialização
3. FOR ALL respostas válidas da API_Pipefy, serializar e depois deserializar os dados SHALL produzir um valor equivalente ao original (propriedade de round-trip)
