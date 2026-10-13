<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\JsonClient;
use Tests\TestCase;

/**
 * Options cURL du proxy dédié aux appels ETF2L : neutres par défaut
 * (sortie directe, comportement inchangé), complètes quand le proxy
 * est configuré.
 */
class JsonClientTest extends TestCase
{
    public function test_sans_proxy_configure_les_options_sont_vides(): void
    {
        config(['hlfr.etf2l_proxy' => '', 'hlfr.etf2l_proxy_auth' => '']);

        $this->assertSame([], JsonClient::etf2lProxyOptions());
    }

    public function test_un_proxy_sans_authentification_ne_declare_que_le_proxy(): void
    {
        config(['hlfr.etf2l_proxy' => 'http://127.0.0.1:3128', 'hlfr.etf2l_proxy_auth' => '']);

        $options = JsonClient::etf2lProxyOptions();

        $this->assertSame([CURLOPT_PROXY => 'http://127.0.0.1:3128'], $options);
    }

    public function test_un_proxy_avec_authentification_declare_userpwd(): void
    {
        config(['hlfr.etf2l_proxy' => 'socks5://10.0.0.1:1080', 'hlfr.etf2l_proxy_auth' => 'user:pass']);

        $options = JsonClient::etf2lProxyOptions();

        $this->assertSame(CURLOPT_PROXY, array_key_first($options));
        $this->assertSame('socks5://10.0.0.1:1080', $options[CURLOPT_PROXY]);
        $this->assertSame('user:pass', $options[CURLOPT_PROXYUSERPWD]);
    }
}
