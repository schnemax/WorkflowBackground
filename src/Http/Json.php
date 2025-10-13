<?php
namespace App\Http;

class Json
{
    public static function body(): array {
        $raw = file_get_contents('php://input') ?: '';
        $data = $raw !== '' ? json_decode($raw, true) : [];
        if ($raw !== '' && !is_array($data)) {
            throw new HttpException(400, 'Invalid JSON body');
        }
        return $data ?: [];
    }

    public static function ok($data): void {
        http_response_code(200);
        echo json_encode($data, JSON_UNESCAPED_UNICODE);
    }

    public static function notFound(string $msg='Not Found'): void {
        throw new HttpException(404, $msg);
    }
}
