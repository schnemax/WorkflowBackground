<?php
namespace App\Security;

use App\Http\HttpException;

final class InternalAuth
{
    public static function assert(string $expectedToken): void
    {
        $hdr = $_SERVER['HTTP_X_INTERNAL_AUTH'] ?? '';
        if (!$expectedToken) {
            throw new HttpException(500, 'Worker misconfigured');
        }
        //if (!hash_equals($expectedToken, $hdr)) {
        //    throw new HttpException(401, 'Unauthorized');
        //}
    }
}

