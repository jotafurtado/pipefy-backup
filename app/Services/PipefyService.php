<?php

namespace App\Services;

use App\Exceptions\PipefyApiException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class PipefyService
{
    public function __construct(
        private string $clientId,
        private string $clientSecret,
        private string $tokenUrl,
        private string $endpoint,
    ) {}

    /**
     * Obtém um access token OAuth2 via Client Credentials grant.
     * O token é cacheado pelo tempo de expiração retornado pela API.
     *
     * @throws PipefyApiException
     */
    private function getAccessToken(): string
    {
        return Cache::remember('pipefy_access_token', 3500, function () {
            try {
                $response = Http::asForm()->post($this->tokenUrl, [
                    'grant_type' => 'client_credentials',
                    'client_id' => $this->clientId,
                    'client_secret' => $this->clientSecret,
                ]);
            } catch (\Illuminate\Http\Client\ConnectionException $e) {
                throw PipefyApiException::connectionError($e->getMessage());
            }

            if ($response->failed()) {
                throw PipefyApiException::oauthError("HTTP {$response->status()}: {$response->body()}");
            }

            $data = $response->json();

            if (! isset($data['access_token'])) {
                throw PipefyApiException::oauthError('access_token ausente na resposta');
            }

            return $data['access_token'];
        });
    }

    /**
     * Executa uma query GraphQL na API do Pipefy.
     *
     * @param  array<string, mixed>  $variables
     * @return array<string, mixed>
     *
     * @throws PipefyApiException
     */
    public function query(string $query, array $variables = []): array
    {
        $token = $this->getAccessToken();

        $payload = ['query' => $query];

        if (! empty($variables)) {
            $payload['variables'] = $variables;
        }

        try {
            $response = Http::withToken($token)
                ->acceptJson()
                ->timeout(120)
                ->post($this->endpoint, $payload);
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            throw PipefyApiException::connectionError($e->getMessage());
        }

        if ($response->status() !== 200) {
            throw PipefyApiException::httpError($response->status(), $response->body());
        }

        $body = $response->body();
        $data = json_decode($body, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw PipefyApiException::invalidResponse('falha ao decodificar JSON');
        }

        if (isset($data['errors'])) {
            throw PipefyApiException::graphqlErrors($data['errors']);
        }

        return $data['data'];
    }

    /**
     * Retorna as organizações do usuário.
     *
     * @return array<int, array{id: int, name: string}>
     */
    public function getOrganizations(): array
    {
        $data = $this->query('{ organizations { id name } }');

        return array_map(fn (array $org) => [
            'id' => (int) $org['id'],
            'name' => $org['name'],
        ], $data['organizations'] ?? []);
    }

    /**
     * Retorna os pipes de uma organização.
     *
     * @return array<int, array{id: int, name: string}>
     */
    public function getPipes(int $organizationId): array
    {
        $data = $this->query(
            'query($id: ID!) { organization(id: $id) { pipes(include_publics: true) { id name } } }',
            ['id' => $organizationId],
        );

        return array_map(fn (array $pipe) => [
            'id' => (int) $pipe['id'],
            'name' => $pipe['name'],
        ], $data['organization']['pipes'] ?? []);
    }

    /**
     * Retorna todos os cards de um pipe, com paginação automática.
     *
     * @return array<int, array<string, mixed>>
     *
     * @throws PipefyApiException
     */
    /**
     * Retorna todos os cards de um pipe, com paginação automática.
     *
     * @param  (\Closure(int): void)|null  $onPageFetched  Callback chamado após cada página com o total acumulado de cards
     * @return array<int, array<string, mixed>>
     *
     * @throws PipefyApiException
     */
    public function getCards(int $pipeId, ?\Closure $onPageFetched = null): array
    {
        $graphql = <<<'GRAPHQL'
        query ($pipeId: ID!, $first: Int!, $after: String) {
            allCards(pipeId: $pipeId, first: $first, after: $after) {
                pageInfo {
                    hasNextPage
                    endCursor
                }
                edges {
                    node {
                        id
                        title
                        assignees {
                            id
                            name
                        }
                        comments {
                            text
                        }
                        comments_count
                        current_phase {
                            name
                        }
                        done
                        due_date
                        fields {
                            name
                            value
                        }
                        labels {
                            name
                        }
                        phases_history {
                            phase {
                                name
                            }
                            firstTimeIn
                            lastTimeOut
                        }
                        url
                    }
                }
            }
        }
        GRAPHQL;

        $cards = [];
        $cursor = null;

        do {
            $variables = [
                'pipeId' => $pipeId,
                'first' => 50,
            ];

            if ($cursor !== null) {
                $variables['after'] = $cursor;
            }

            $data = $this->query($graphql, $variables);

            $allCards = $data['allCards'];

            foreach ($allCards['edges'] as $edge) {
                $cards[] = $edge['node'];
            }

            if ($onPageFetched) {
                $onPageFetched(count($cards));
            }

            $pageInfo = $allCards['pageInfo'];
            $cursor = $pageInfo['endCursor'];
        } while ($pageInfo['hasNextPage']);

        return $cards;
    }

    /**
     * Itera sobre os cards de um pipe página por página, sem acumular em memória.
     *
     * @param  \Closure(array<int, array<string, mixed>>, int): void  $onPage  Callback recebe (cards da página, total acumulado)
     * @return int Total de cards processados
     *
     * @throws PipefyApiException
     */
    public function eachCardPage(int $pipeId, \Closure $onPage): int
    {
        $graphql = <<<'GRAPHQL'
        query ($pipeId: ID!, $first: Int!, $after: String) {
            allCards(pipeId: $pipeId, first: $first, after: $after) {
                pageInfo {
                    hasNextPage
                    endCursor
                }
                edges {
                    node {
                        id
                        title
                        assignees {
                            id
                            name
                        }
                        comments {
                            text
                        }
                        comments_count
                        current_phase {
                            name
                        }
                        done
                        due_date
                        fields {
                            name
                            value
                        }
                        labels {
                            name
                        }
                        phases_history {
                            phase {
                                name
                            }
                            firstTimeIn
                            lastTimeOut
                        }
                        url
                    }
                }
            }
        }
        GRAPHQL;

        $total = 0;
        $cursor = null;

        do {
            $variables = [
                'pipeId' => $pipeId,
                'first' => 50,
            ];

            if ($cursor !== null) {
                $variables['after'] = $cursor;
            }

            $data = $this->query($graphql, $variables);

            $allCards = $data['allCards'];

            $pageCards = array_map(
                fn (array $edge) => $edge['node'],
                $allCards['edges'],
            );

            $total += count($pageCards);

            $onPage($pageCards, $total);

            $pageInfo = $allCards['pageInfo'];
            $cursor = $pageInfo['endCursor'];
        } while ($pageInfo['hasNextPage']);

        return $total;
    }

    /**
     * Retorna os attachments de um card específico.
     *
     * @return array<int, array{id: string, filename: string, url: string, createdAt: string, path: string}>
     *
     * @throws PipefyApiException
     */
    /**
     * Retorna os attachments de um card específico.
     *
     * @return array<int, array{url: string, createdAt: string, path: string, filename: string}>
     *
     * @throws PipefyApiException
     */
    public function getCardAttachments(int $cardId): array
    {
        $graphql = <<<'GRAPHQL'
        query ($cardId: ID!) {
            card(id: $cardId) {
                attachments {
                    url
                    createdAt
                    path
                }
            }
        }
        GRAPHQL;

        $data = $this->query($graphql, ['cardId' => $cardId]);

        $attachments = $data['card']['attachments'] ?? [];

        return array_map(function (array $attachment) {
            $attachment['filename'] = basename($attachment['path'] ?? '') ?: 'unknown';

            return $attachment;
        }, $attachments);
    }
}
