# Pipefy Backup

[![Licença: MIT](https://img.shields.io/badge/License-MIT-blue.svg)](LICENSE)
[![PHP](https://img.shields.io/badge/PHP-8.3%20%7C%208.4-777bb4.svg?logo=php)](https://www.php.net/)
[![Laravel](https://img.shields.io/badge/Laravel-13.x-ff2d20.svg?logo=laravel)](https://laravel.com)
[![Testes](https://img.shields.io/badge/testes-73%20passando-brightgreen.svg)](#testes)

[Português (Brasil)](README.md) · [English](README.en.md)

Solução automatizada, resiliente e pronta para produção para backup de organizações no [Pipefy](https://www.pipefy.com/). Como o Pipefy não possui um recurso nativo de backup/exportação completa de todos os pipes, cards e anexos com um clique, este projeto supre essa necessidade, exportando dados estruturados em JSON e arquivos binários para disco local, com monitoramento via dashboard web em tempo real e sincronização para arquivamento no AWS S3 / Glacier.

---

## Recursos e Funcionalidades

- **Exportação Completa de Dados**: Salva o payload JSON integral de cada card (campos personalizados, histórico de fases, comentários, responsáveis, etiquetas) via API GraphQL do Pipefy.
- **Armazenamento de Anexos sem Colisão**: Baixa todos os anexos organizados pelo UUID de upload do Pipefy (`uploads/{pathUuid}/{filename}`), evitando que arquivos com o mesmo nome no mesmo card se sobrescrevam.
- **Verificação de Integridade**: Realiza requisições HEAD para obter `Content-Length`, persiste o tamanho no JSON do card e valida os arquivos em disco.
- **Resiliência e Tolerância a Rate Limits**: Renovação automática de token OAuth em respostas 401, backoff exponencial em limitações de taxa e recuperação de falhas transitórias de rede.
- **Retentativa Inteligente (`--retry-errored`)**: Re-processa apenas os anexos faltantes ou com erro, sem baixar novamente os arquivos já validados em disco.
- **Idempotência (Skip-if-Exists)**: O processo pode ser interrompido e reiniciado a qualquer momento com segurança; arquivos já existentes e válidos são pulados.
- **Dashboard Web em Tempo Real**: Interface visual em Laravel e Tailwind CSS com barras de progresso por pipe, taxas de conclusão, logs de erro e botão de retentativa com um clique.
- **Reciclagem de Memória nos Workers**: Scripts de loop para Windows (`worker-loop.bat`) e Linux/macOS (`worker-loop.sh`) que reciclam os processos dos workers do Laravel para evitar vazamento de memória em backups gigantes e de longa duração.
- **Pronto para AWS S3 e Glacier**: Comandos e parâmetros testados para sincronizar backups com AWS S3 (incluindo a classe Glacier Instant Retrieval para baixo custo e recuperação em milissegundos).

---

## Estrutura de Diretórios do Backup

Todos os arquivos são armazenados em `storage/app/private/pipefy-backup/`:

```
storage/app/private/pipefy-backup/
└── {pipeId}/
    ├── cards/
    │   ├── {cardId}.json          # JSON completo do card (campos, histórico, metadados dos anexos)
    │   └── index.json             # Índice consolidado do pipe (lista de cards, contagens, timestamp)
    └── attachments/
        └── {cardId}/
            └── {pathUuid}/        # UUID do upload no Pipefy (evita sobrescrita de mesmo nome)
                └── {filename}     # Arquivo binário baixado
```

---

## Pré-requisitos

- **PHP**: 8.3 ou 8.4 (extensões recomendadas: `bcmath`, `curl`, `mbstring`, `openssl`, `pdo_sqlite` ou `pdo_mysql`, `fileinfo`)
- **Composer**: 2.x
- **Node.js**: 18+ e `npm`
- **Redis** *(altamente recomendado para organizações grandes com mais de 10k anexos)* ou fila via banco de dados (SQLite/MySQL)
- **Conta no Pipefy** com permissão de administrador para gerar credenciais de API
- **AWS CLI** *(opcional, caso queira subir os arquivos para a nuvem)*

---

## Instalação e Configuração

### 1. Clonar o repositório

```bash
git clone https://github.com/jotafurtado/pipefy-backup.git
cd pipefy-backup
```

### 2. Executar o setup automatizado

Utilize o script de configuração do Composer:

```bash
composer run setup
```

*Esse comando executa automaticamente:*
1. Instalação das dependências PHP (`composer install`)
2. Criação do arquivo `.env` a partir do `.env.example`
3. Geração da chave da aplicação (`php artisan key:generate`)
4. Execução das migrações do banco de dados (`php artisan migrate --force`)
5. Instalação das dependências de frontend e compilação (`npm install && npm run build`)

---

## Configuração do Ambiente

Abra o arquivo `.env` gerado e configure as credenciais do Pipefy:

```env
APP_NAME="Pipefy Backup"
APP_ENV=local
APP_KEY=base64:...
APP_URL=http://localhost:8000

# Banco de dados (SQLite é o padrão e não necessita de servidor externo)
DB_CONNECTION=sqlite

# Fila (Redis é recomendado para alta volumetria)
QUEUE_CONNECTION=redis
REDIS_CLIENT=predis
REDIS_HOST=127.0.0.1
REDIS_PORT=6379

# Credenciais da API do Pipefy
PIPEFY_CLIENT_ID=seu_client_id_aqui
PIPEFY_CLIENT_SECRET=seu_client_secret_aqui
PIPEFY_ORGANIZATION_ID=seu_organization_id_aqui
PIPEFY_TOKEN_URL=https://app.pipefy.com/oauth/token
PIPEFY_API_ENDPOINT=https://api.pipefy.com/graphql
```

### Como obter as credenciais do Pipefy

1. **Client ID e Client Secret**:
   - No Pipefy, vá em **Preferências da Conta** -> **Desenvolvedores / API**.
   - Crie um novo aplicativo OAuth ou Token Pessoal para obter o `Client ID` e o `Client Secret`.
2. **Organization ID**:
   - O ID da sua organização pode ser obtido diretamente na URL do navegador ao acessar o Pipefy (ex.: `https://app.pipefy.com/organizations/1234567`) ou nas configurações da organização.

---

## Executando a Aplicação e os Workers

Para processar milhares de requisições GraphQL e downloads de forma estável, o sistema opera via workers assíncronos em background.

### Iniciar o servidor e os workers

**No Windows:**
```bash
composer run dev-windows
```
*(Inicia o servidor web na porta 8000, 4 workers de fila com reciclagem periódica e o monitor Laravel Pulse)*

Para rodar com 8 workers simultâneos no Windows:
```bash
composer run dev-windows:8w
```

**No Linux / macOS:**
```bash
composer run dev-linux
```
*(Inicia o servidor web, 4 workers com reciclagem via `worker-loop.sh` e o monitor Laravel Pulse)*

Ou inicie manualmente via comando do Laravel:
```bash
php artisan queue:work redis --timeout=0 --sleep=3 --tries=3
```

---

## Executando o Backup

### 1. Iniciar o backup de todos os pipes

Dispara os jobs de backup para todos os pipes da organização configurada:

```bash
php artisan pipefy:backup-all
```

### 2. Acompanhar pelo Dashboard Web

Acesse `http://localhost:8000` no seu navegador:
- Barra de progresso em tempo real para cada pipe.
- Contagem total de cards concluídos, pendentes e com falha.
- Métricas de download de anexos e logs detalhados de erros.
- Botão interativo para retentar cards com erro com apenas um clique.

### 3. Tratamento de Erros e Retentativas

Se ocorrerem quedas de conexão ou instabilidades na API durante o processo:

```bash
# Re-despacha apenas os cards que tiveram erros em anexos (baixa só o que faltou):
php artisan pipefy:backup-all --retry-errored

# Re-despacha cards com falha total:
php artisan pipefy:backup-all --retry
```

---

## Outros Comandos Artisan Úteis

| Comando | Descrição |
|---|---|
| `php artisan pipefy:pipes` | Lista todos os pipes da organização configurada. |
| `php artisan pipefy:backup-status` | Exibe resumo no terminal do status dos backups e erros. |
| `php artisan pipefy:verify-backup` | Valida integridade dos arquivos locais contra os manifestos JSON e tamanhos. |
| `php artisan pipefy:verify-backup --pipe={id}` | Valida a integridade de um pipe específico. |
| `php artisan pipefy:cleanup-attachments --dry-run` | Lista anexos órfãos de versões antigas sem removê-los. |
| `php artisan pipefy:cleanup-attachments` | Remove arquivos de anexo órfãos em definitivo. |

---

## Arquivamento no AWS S3 / Glacier

Após a conclusão do backup em disco local, você pode sincronizar tudo para a AWS de maneira segura e econômica:

### Recomendado: S3 Glacier Instant Retrieval

Ideal para retenção e compliance com custo muito baixo de armazenamento e acesso imediato em milissegundos quando necessário:

```bash
aws s3 sync storage/app/private/pipefy-backup s3://seu-bucket/pipefy-backup/ \
  --exclude "*.tmp" \
  --storage-class GLACIER_IR
```

*Parâmetros recomendados:*
- `--storage-class GLACIER_IR`: Classe de armazenamento Glacier Instant Retrieval.
- `--exclude "*.tmp"`: Ignora arquivos de download temporários.
- `--delete`: Remove no S3 arquivos que foram apagados localmente (opcional).
- `--dryrun`: Simula a transferência para visualização prévia.

---

## Leitura e Restauração dos Dados

Os dados são armazenados em JSON limpo e formato padrão de pastas, permitindo fácil leitura e integração com outras ferramentas:

1. **Índice do Pipe**: `storage/app/private/pipefy-backup/{pipeId}/cards/index.json`
2. **Dados do Card**: `storage/app/private/pipefy-backup/{pipeId}/cards/{cardId}.json`
3. **Anexos**: `storage/app/private/pipefy-backup/{pipeId}/attachments/{cardId}/{pathUuid}/{filename}`

Exemplo de consulta com `jq`:
```bash
# Listar todos os cards de um pipe
cat storage/app/private/pipefy-backup/123456/cards/index.json | jq '.cards[] | {id, title}'

# Ver anexos de um card específico
cat storage/app/private/pipefy-backup/123456/cards/789012.json | jq '.attachments[] | {filename, path, content_length}'
```

---

## Decisões de Arquitetura (ADRs)

As decisões de projeto estão documentadas em `docs/adr/`:
- [ADR-0001: Identificação de Caminho de Anexos por UUID de Upload do Pipefy](docs/adr/0001-attachment-path-by-pipefy-upload-uuid.md)
- [ADR-0002: Resiliência a Rate Limiting e Download de Anexos no Pipefy](docs/adr/0002-pipefy-rate-limiting-and-download-resilience.md)

---

## Testes

Execute a suíte de testes automatizados com Pest / PHPUnit:

```bash
php artisan test --compact
```

Formatação de código com Laravel Pint:

```bash
vendor/bin/pint --format agent
```

---

## Contribuição

Contribuições da comunidade são muito bem-vindas! Sinta-se à vontade para abrir uma Issue ou enviar um Pull Request:

1. Faça um Fork do projeto
2. Crie uma branch para sua funcionalidade (`git checkout -b feature/minha-feature`)
3. Faça o commit das suas alterações (`git commit -m 'Adiciona funcionalidade x'`)
4. Faça o push para a branch (`git push origin feature/minha-feature`)
5. Abra um Pull Request

---

## Licença

Este projeto é um software de código aberto licenciado sob a licença [MIT](LICENSE).
