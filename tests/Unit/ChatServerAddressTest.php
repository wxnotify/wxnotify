<?php

use App\Support\Delivery\ChatServerAddress;

test('it spells a server address one way', function (string $server, array $parts, string $url, string $hostAndPort) {
    expect(ChatServerAddress::parts($server))->toBe($parts)
        ->and(ChatServerAddress::toUrl($parts))->toBe($url)
        ->and(ChatServerAddress::hostAndPort($parts))->toBe($hostAndPort);
})->with([
    'plain https' => ['https://chat.example.com', ['scheme' => 'https', 'host' => 'chat.example.com', 'port' => null], 'https://chat.example.com', 'chat.example.com:443'],
    'upper case' => ['HTTPS://Chat.Example.COM', ['scheme' => 'https', 'host' => 'chat.example.com', 'port' => null], 'https://chat.example.com', 'chat.example.com:443'],
    'trailing dot and slash' => ['https://chat.example.com./', ['scheme' => 'https', 'host' => 'chat.example.com', 'port' => null], 'https://chat.example.com', 'chat.example.com:443'],
    'explicit 443 dropped' => ['https://chat.example.com:443', ['scheme' => 'https', 'host' => 'chat.example.com', 'port' => null], 'https://chat.example.com', 'chat.example.com:443'],
    'another port kept' => ['https://chat.example.com:8065', ['scheme' => 'https', 'host' => 'chat.example.com', 'port' => 8065], 'https://chat.example.com:8065', 'chat.example.com:8065'],
    'http with 80 dropped' => ['http://mm.lan.example:80', ['scheme' => 'http', 'host' => 'mm.lan.example', 'port' => null], 'http://mm.lan.example', 'mm.lan.example:80'],
    'http with 443 kept' => ['http://mm.lan.example:443', ['scheme' => 'http', 'host' => 'mm.lan.example', 'port' => 443], 'http://mm.lan.example:443', 'mm.lan.example:443'],
    'IPv6 canonicalised' => ['https://[2606:4700:0:0::1]:8443', ['scheme' => 'https', 'host' => '[2606:4700::1]', 'port' => 8443], 'https://[2606:4700::1]:8443', '[2606:4700::1]:8443'],
    'IPv6 upper case, default port' => ['https://[2606:4700::ABCD]:443/', ['scheme' => 'https', 'host' => '[2606:4700::abcd]', 'port' => null], 'https://[2606:4700::abcd]', '[2606:4700::abcd]:443'],
    'IPv4 literal' => ['https://93.184.216.34', ['scheme' => 'https', 'host' => '93.184.216.34', 'port' => null], 'https://93.184.216.34', '93.184.216.34:443'],
]);

test('it reads nothing that is not a bare http or https server address', function (string $server) {
    expect(ChatServerAddress::parts($server))->toBeNull();
})->with([
    'empty' => '',
    'no scheme' => 'chat.example.com',
    'ftp' => 'ftp://chat.example.com',
    'a path' => 'https://chat.example.com/mm',
    'userinfo' => 'https://user@chat.example.com',
    'a query' => 'https://chat.example.com?x=1',
    'a trailing newline' => "https://chat.example.com\n",
    'a newline before the port' => "https://chat.example.com\n:8065",
    'an empty port' => 'https://chat.example.com:',
    'a bad IPv6 literal' => 'https://[2606:zz::1]',
    'only a dot' => 'https://.',
    'unicode' => 'https://chät.example.com',
]);
