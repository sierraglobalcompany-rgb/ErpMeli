<?php

declare(strict_types=1);

namespace App\Services;

use RuntimeException;

final class PrivateArchiveCipher
{
    private const MAGIC = "ERP-MELI-ARCHIVE-1\n";
    private const CHUNK = 1048576;

    public function encrypt(string $source, string $target, string $keyId, string $key): void
    {
        [$state, $header] = sodium_crypto_secretstream_xchacha20poly1305_init_push($key);
        $input = @fopen($source, 'rb');
        $output = @fopen($target, 'xb');
        if (!is_resource($input) || !is_resource($output)) {
            throw new RuntimeException('No fue posible abrir el archivo histórico para cifrarlo.');
        }
        try {
            fwrite($output, self::MAGIC . pack('n', strlen($keyId)) . $keyId . $header);
            while (!feof($input)) {
                $chunk = fread($input, self::CHUNK);
                if (!is_string($chunk)) {
                    throw new RuntimeException('No fue posible leer el archivo histórico.');
                }
                $cipher = sodium_crypto_secretstream_xchacha20poly1305_push(
                    $state,
                    $chunk,
                    '',
                    feof($input)
                        ? SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL
                        : SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE
                );
                fwrite($output, pack('N', strlen($cipher)) . $cipher);
            }
            fflush($output);
        } finally {
            fclose($input);
            fclose($output);
        }
        @chmod($target, 0600);
    }

    /** @return array{key_id:string,sha256:string,size:int} */
    public function decryptTo(string $source, string $target): array
    {
        $input = @fopen($source, 'rb');
        $output = @fopen($target, 'xb');
        if (!is_resource($input) || !is_resource($output)) {
            throw new RuntimeException('No fue posible abrir el archivo histórico para verificarlo.');
        }
        $hash = hash_init('sha256');
        $plainBytes = 0;
        try {
            if (fread($input, strlen(self::MAGIC)) !== self::MAGIC) {
                throw new RuntimeException('El archivo histórico no tiene un formato compatible.');
            }
            $lengthRaw = fread($input, 2);
            $length = is_string($lengthRaw) && strlen($lengthRaw) === 2
                ? (int) (unpack('nlength', $lengthRaw)['length'] ?? 0)
                : 0;
            if ($length < 4 || $length > 120) {
                throw new RuntimeException('El identificador de clave no es válido.');
            }
            $keyId = (string) fread($input, $length);
            $header = fread($input, SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES);
            $key = (new BackupKeyringService())->key($keyId);
            $state = sodium_crypto_secretstream_xchacha20poly1305_init_pull($header, $key);
            $final = false;
            while (!feof($input)) {
                $sizeRaw = fread($input, 4);
                if ($sizeRaw === '') {
                    break;
                }
                if (!is_string($sizeRaw) || strlen($sizeRaw) !== 4) {
                    throw new RuntimeException('El archivo histórico está truncado.');
                }
                $size = (int) (unpack('Nsize', $sizeRaw)['size'] ?? 0);
                if ($size < 17 || $size > self::CHUNK + 1024) {
                    throw new RuntimeException('El archivo histórico contiene un bloque inválido.');
                }
                $cipher = '';
                while (strlen($cipher) < $size) {
                    $piece = fread($input, $size - strlen($cipher));
                    if (!is_string($piece) || $piece === '') {
                        throw new RuntimeException('El archivo histórico está truncado.');
                    }
                    $cipher .= $piece;
                }
                $decoded = sodium_crypto_secretstream_xchacha20poly1305_pull($state, $cipher);
                if ($decoded === false) {
                    throw new RuntimeException('El archivo histórico no superó su autenticación.');
                }
                [$plain, $tag] = $decoded;
                fwrite($output, $plain);
                hash_update($hash, $plain);
                $plainBytes += strlen($plain);
                $final = $tag === SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL;
            }
            if (!$final) {
                throw new RuntimeException('El archivo histórico no tiene cierre autenticado.');
            }
            return ['key_id' => $keyId, 'sha256' => hash_final($hash), 'size' => $plainBytes];
        } finally {
            fclose($input);
            fclose($output);
        }
    }
}
