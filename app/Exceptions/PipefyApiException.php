<?php

namespace App\Exceptions;

use RuntimeException;

class PipefyApiException extends RuntimeException
{
    public static function missingToken(): self
    {
        return new self('Credenciais OAuth do Pipefy não configuradas. Defina PIPEFY_CLIENT_ID e PIPEFY_CLIENT_SECRET no .env');
    }

    public static function oauthError(string $message): self
    {
        return new self("Falha ao obter token OAuth do Pipefy: {$message}");
    }

    public static function connectionError(string $message): self
    {
        return new self("Falha ao conectar com a API do Pipefy: {$message}");
    }

    public static function invalidResponse(string $message): self
    {
        return new self("Resposta inválida da API do Pipefy: {$message}");
    }

    /**
     * @param  array<int, array{message: string}>  $errors
     */
    public static function graphqlErrors(array $errors): self
    {
        $message = $errors[0]['message'] ?? 'Erro desconhecido';

        return new self("Erro da API do Pipefy: {$message}");
    }

    public static function httpError(int $statusCode, string $body): self
    {
        return new self("Erro HTTP {$statusCode} da API do Pipefy: {$body}");
    }
}
