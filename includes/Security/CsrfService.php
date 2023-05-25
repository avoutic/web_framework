<?php

namespace WebFramework\Security;

use WebFramework\Core\BrowserSessionService;

class CsrfService
{
    public function __construct(
        private BrowserSessionService $browser_session_service,
    ) {
    }

    protected function get_random_bytes(): string
    {
        return openssl_random_pseudo_bytes(16);
    }

    protected function store_new_token(): void
    {
        $this->browser_session_service->set('csrf_token', $this->get_random_bytes());
    }

    protected function get_stored_token(): string
    {
        return $this->browser_session_service->get('csrf_token');
    }

    protected function is_valid_token_stored(): bool
    {
        $token = $this->browser_session_service->get('csrf_token');

        return ($token !== null && strlen($token) == 16);
    }

    public function get_token(): string
    {
        if (!$this->is_valid_token_stored())
        {
            $this->store_new_token();
        }

        $token = $this->get_stored_token();

        $xor = $this->get_random_bytes();
        for ($i = 0; $i < 16; $i++)
        {
            $token[$i] = chr(ord($xor[$i]) ^ ord($token[$i]));
        }

        return bin2hex($xor).bin2hex($token);
    }

    public function validate_token(string $token): bool
    {
        if (!$this->is_valid_token_stored())
        {
            return false;
        }

        $check = $this->get_stored_token();
        $value = $token;
        if (strlen($value) != 16 * 4 || strlen($check) != 16)
        {
            return false;
        }

        $xor = pack('H*', substr($value, 0, 16 * 2));
        $token = pack('H*', substr($value, 16 * 2, 16 * 2));

        // Slow compare (time-constant)
        $diff = 0;
        for ($i = 0; $i < 16; $i++)
        {
            $token[$i] = chr(ord($xor[$i]) ^ ord($token[$i]));
            $diff |= ord($token[$i]) ^ ord($check[$i]);
        }

        return ($diff === 0);
    }
}