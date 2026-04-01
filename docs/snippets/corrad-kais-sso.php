<?php

/**
 * Tampal fail ini ke projek CORRAD (MYFIS) atau include dari config bootstrap.
 *
 * Tetapkan dalam .env / config CORRAD (JANGAN commit secret ke git awam):
 *   KAIS_BASE_URL=https://portal-kais.contoh.gov.my
 *   CORRAD_KAIS_SSO_SECRET=sama_dengan_CORRAD_SSO_SECRET_di_Laravel
 *
 * Payload ditandatangani: strtolower(email)|timestamp|nonce
 */

declare(strict_types=1);

if (! function_exists('kais_fullpage_user_chat_url')) {
    /**
     * URL penuh untuk buka KAIS User Chat (full page) dengan SSO.
     *
     * @param  string  $corradUserEmail  E-mel pengguna log masuk CORRAD (mesti wujud dalam jadual users KAIS)
     * @param  string  $corradUserName  Pilihan — untuk paparan log sahaja di CORRAD
     */
    function kais_fullpage_user_chat_url(string $corradUserEmail, string $corradUserName = ''): string
    {
        $base = rtrim(getenv('KAIS_BASE_URL') ?: ($_ENV['KAIS_BASE_URL'] ?? ''), '/');
        $secret = getenv('CORRAD_KAIS_SSO_SECRET') ?: ($_ENV['CORRAD_KAIS_SSO_SECRET'] ?? '');

        if ($base === '' || $secret === '') {
            error_log('[kais_sso] Set KAIS_BASE_URL and CORRAD_KAIS_SSO_SECRET');

            return $base.'/admin/login';
        }

        $email = strtolower(trim($corradUserEmail));
        $ts = time();
        $nonce = bin2hex(random_bytes(16));
        $payload = $email.'|'.$ts.'|'.$nonce;
        $sig = hash_hmac('sha256', $payload, $secret);

        $query = http_build_query([
            'email' => $email,
            'name' => $corradUserName,
            'ts' => $ts,
            'nonce' => $nonce,
            'sig' => $sig,
            'redirect' => '/admin/kerisi/user-chat',
        ]);

        return $base.'/auth/corrad-sso?'.$query;
    }
}
