<?php

namespace App\Cast;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

final class JsonBody
{
    /** @return array<string, mixed> */
    public static function of(Request $request): array
    {
        if ('' === $request->getContent()) {
            return [];
        }
        try {
            $data = json_decode($request->getContent(), true, 64, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new BadRequestHttpException('Corps JSON invalide.');
        }
        if (!\is_array($data) || array_is_list($data) && [] !== $data) {
            throw new BadRequestHttpException('Un objet JSON est attendu.');
        }

        return $data;
    }
}
