<?php
declare(strict_types=1);

namespace StageCms\Identity;

use Stage\Security\Caller;
use StageCms\Failure;
use StageCms\Infrastructure\Database;
use StageCms\Input;

final readonly class Identity
{
    public const array PERMISSIONS = ['admin', 'content:read', 'content:write', 'content:publish', 'media:write'];
    public const array TOKEN_SCOPES = ['content:read', 'content:write', 'content:publish', 'media:write'];

    public function __construct(private Database $db) {}

    /** @return array{name: string, email: string}|null */
    public function owner(): ?array
    {
        $row = $this->db->one('SELECT name, email FROM owner WHERE id = 1');
        return $row === null ? null : ['name' => Input::text($row, 'name'), 'email' => Input::text($row, 'email')];
    }

    public function setup(string $name, string $email, string $password): void
    {
        if (trim($name) === '' || mb_strlen($name) > 100 || !filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 200) {
            throw new Failure(422, 'invalid_owner', 'Provide your name and a valid email address.');
        }
        self::validatePassword($password);
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $this->db->transaction(function () use ($name, $email, $hash): void {
            if ($this->owner() !== null) {
                throw new Failure(409, 'already_configured', 'The owner already exists.');
            }
            $this->db->execute('INSERT INTO owner VALUES (1, :name, :email, :hash)', [
                'name' => trim($name), 'email' => strtolower(trim($email)), 'hash' => $hash,
            ]);
        });
    }

    public function newSession(bool $authenticated = false): Session
    {
        $session = new Session(bin2hex(random_bytes(32)), bin2hex(random_bytes(32)), $authenticated, time() + 28800);
        $this->db->execute('DELETE FROM sessions WHERE expires < :now', ['now' => time()]);
        $this->db->execute('INSERT INTO sessions VALUES (:hash, :csrf, :authenticated, :expires)', [
            'hash' => hash('sha256', $session->secret), 'csrf' => $session->csrf, 'authenticated' => $authenticated ? 1 : 0, 'expires' => $session->expires,
        ]);
        return $session;
    }

    public function session(?string $secret): ?Session
    {
        if ($secret === null || !preg_match('/^[a-f0-9]{64}$/D', $secret)) {
            return null;
        }
        $row = $this->db->one('SELECT csrf, authenticated, expires FROM sessions WHERE hash = :hash AND expires > :now',
            ['hash' => hash('sha256', $secret), 'now' => time()]);
        return $row === null ? null : new Session($secret, Input::text($row, 'csrf'), $row['authenticated'] === 1, Input::integer($row['expires']));
    }

    public function login(Session $session, string $email, string $password, string $address): Session
    {
        $key = hash('sha256', $address);
        $attempt = $this->db->one('SELECT attempts FROM login_attempts WHERE key = :key AND expires > :now', ['key' => $key, 'now' => time()]);
        if ($attempt !== null && is_int($attempt['attempts']) && $attempt['attempts'] >= 10) {
            throw new Failure(429, 'rate_limited', 'Too many sign-in attempts. Try again in 15 minutes.');
        }
        $owner = $this->db->one('SELECT email, password_hash FROM owner WHERE id = 1');
        $validPassword = $owner !== null && password_verify($password, Input::text($owner, 'password_hash'));
        if (!$validPassword || !hash_equals(Input::text($owner, 'email'), strtolower(trim($email)))) {
            $this->db->execute('INSERT INTO login_attempts VALUES (:key, 1, :expires) ON CONFLICT(key) DO UPDATE SET attempts = CASE WHEN expires < :now THEN 1 ELSE attempts + 1 END, expires = :expires',
                ['key' => $key, 'expires' => time() + 900, 'now' => time()]);
            throw new Failure(401, 'invalid_credentials', 'The email or password is not correct.');
        }
        return $this->db->transaction(function () use ($session, $key): Session {
            $this->logout($session);
            $this->db->execute('DELETE FROM login_attempts WHERE key = :key', ['key' => $key]);
            return $this->newSession(true);
        });
    }

    public function logout(Session $session): void
    {
        $this->db->execute('DELETE FROM sessions WHERE hash = :hash', ['hash' => hash('sha256', $session->secret)]);
    }

    public function changePassword(Caller $caller, string $password): void
    {
        $caller->require('admin');
        self::validatePassword($password);
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $this->db->transaction(function () use ($hash): void {
            $this->db->execute('UPDATE owner SET password_hash = :hash WHERE id = 1', ['hash' => $hash]);
            $this->db->execute('DELETE FROM sessions');
        });
    }

    /** @param list<string> $scopes */
    public function createToken(Caller $caller, string $name, array $scopes, int $days = 90): string
    {
        $caller->require('admin');
        if (trim($name) === '' || mb_strlen($name) > 80 || $days < 1 || $days > 365 || $scopes === [] || array_diff($scopes, self::TOKEN_SCOPES) !== []) {
            throw new Failure(422, 'invalid_token', 'Name the connection, choose its permissions, and set an expiry of 1 to 365 days.');
        }
        if (!in_array('content:read', $scopes, true)) {
            $scopes[] = 'content:read';
        }
        $secret = 'stg_' . bin2hex(random_bytes(32));
        $this->db->execute('INSERT INTO tokens (id, hash, name, scopes, expires, created_at) VALUES (:id, :hash, :name, :scopes, :expires, :created)', [
            'id' => bin2hex(random_bytes(16)), 'hash' => hash('sha256', $secret), 'name' => trim($name),
            'scopes' => json_encode(array_values(array_unique($scopes)), JSON_THROW_ON_ERROR), 'expires' => time() + $days * 86400, 'created' => gmdate('c'),
        ]);
        return $secret;
    }

    public function authenticateToken(string $authorization): Caller
    {
        if (!preg_match('/^Bearer (stg_[a-f0-9]{64})$/D', $authorization, $match)) {
            throw new Failure(401, 'unauthorized', 'Provide a valid bearer token.');
        }
        $row = $this->db->one('SELECT id, scopes FROM tokens WHERE hash = :hash AND expires > :now AND revoked_at IS NULL',
            ['hash' => hash('sha256', $match[1]), 'now' => time()]);
        if ($row === null) {
            throw new Failure(401, 'unauthorized', 'This token is invalid, expired, or revoked.');
        }
        $decoded = json_decode(Input::text($row, 'scopes'), true, 8, JSON_THROW_ON_ERROR);
        $scopes = [];
        if (is_array($decoded)) {
            foreach ($decoded as $scope) {
                if (is_string($scope) && in_array($scope, self::TOKEN_SCOPES, true)) {
                    $scopes[] = $scope;
                }
            }
        }
        $id = Input::text($row, 'id');
        $this->db->execute('UPDATE tokens SET last_used = :now WHERE id = :id', ['now' => gmdate('c'), 'id' => $id]);
        return new Caller('agent:' . $id, $scopes);
    }

    /** @return list<array<string, mixed>> */
    public function tokens(Caller $caller): array
    {
        $caller->require('admin');
        return $this->db->all('SELECT id, name, scopes, expires, created_at, last_used, revoked_at FROM tokens ORDER BY created_at DESC');
    }

    public function revokeToken(Caller $caller, string $id): void
    {
        $caller->require('admin');
        if ($this->db->execute('UPDATE tokens SET revoked_at = :now WHERE id = :id AND revoked_at IS NULL', ['id' => $id, 'now' => gmdate('c')]) !== 1) {
            throw new Failure(404, 'not_found', 'This connection is missing or already revoked.');
        }
    }

    private static function validatePassword(string $password): void
    {
        if (strlen($password) < 12 || strlen($password) > 72 || str_contains($password, "\0")) {
            throw new Failure(422, 'invalid_password', 'Use a password between 12 and 72 bytes.');
        }
    }
}
