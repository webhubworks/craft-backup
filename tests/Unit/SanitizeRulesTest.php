<?php

use Faker\Factory;
use Faker\Generator;
use webhubworks\backup\services\sanitize\CraftSchema;
use webhubworks\backup\services\sanitize\SanitizeRules;

function fakeSchema(bool $emailAsUsername = false, bool $legacyContent = true): CraftSchema
{
    return new class($emailAsUsername, $legacyContent) extends CraftSchema {
        public function __construct(private readonly bool $emailAsUsername, private readonly bool $legacyContent)
        {
        }

        public function hasLegacyContentTables(): bool
        {
            return $this->legacyContent;
        }

        public function useEmailAsUsername(): bool
        {
            return $this->emailAsUsername;
        }

        public function hashPassword(string $password): string
        {
            return "hashed:{$password}";
        }
    };
}

it('keeps admins by default and nobody when keep_users is null', function() {
    $rules = SanitizeRules::fromConfig([], fakeSchema());

    expect(($rules->keepUsers)(['admin' => 1]))->toBeTrue()
        ->and(($rules->keepUsers)(['admin' => 0]))->toBeFalse()
        ->and(SanitizeRules::fromConfig(['keep_users' => null], fakeSchema())->keepUsers)->toBeNull();
});

it('merges project rules over the preset and only marks preset-only tables optional', function() {
    $rules = SanitizeRules::fromConfig([
        'empty_tables' => ['formie_submissions', 'sessions'],
        'tables' => [
            'users' => ['firstName' => 'word'],
            'commerce_orders' => ['email' => 'safeEmail'],
        ],
    ], fakeSchema());

    expect($rules->emptyTables)->toContain('searchindex', 'formie_submissions')
        ->and(array_count_values($rules->emptyTables)['sessions'])->toBe(1)
        ->and($rules->tables['users']['firstName'])->toBe('word')
        ->and($rules->tables['users'])->toHaveKey('email')
        ->and($rules->tables['commerce_orders']['email'])->toBe('safeEmail')
        ->and($rules->optionalTables)->toContain('searchindex', 'addresses')
        ->and($rules->optionalTables)->not->toContain('sessions', 'users', 'formie_submissions', 'commerce_orders')
        ->and($rules->optionalColumns['users'])->toContain('rememberToken')
        ->and($rules->optionalColumns['users'])->not->toContain('firstName');
});

it('rejects rules that are neither a formatter nor a closure', function() {
    SanitizeRules::fromConfig(['fields' => ['phone' => 123]], fakeSchema());
})->throws(InvalidArgumentException::class, 'sanitize.fields.phone');

it('sanitizes a user row: new email, username from email, hashed password, empty values untouched', function() {
    $rules = SanitizeRules::fromConfig([], fakeSchema(emailAsUsername: true));
    $row = [
        'id' => 7,
        'email' => 'jane@client.de',
        'username' => 'jane@client.de',
        'firstName' => 'Jane',
        'lastName' => null,
        'fullName' => 'Jane',
        'password' => '$2y$13$original',
        'lastLoginAttemptIp' => '203.0.113.9',
    ];

    $changes = SanitizeRules::applyToRow($rules->tables['users'], $row, Factory::create());

    expect($changes['email'])->not->toBe('jane@client.de')
        ->and($changes['email'])->toMatch('/@example\.(com|org|net)$/')
        ->and($changes['username'])->toBe($changes['email'])
        ->and($changes['password'])->toBe('hashed:password')
        ->and($changes['lastLoginAttemptIp'])->toBeNull()
        ->and($changes['fullName'])->toBe($changes['firstName'])
        ->and($changes)->not->toHaveKey('lastName')
        ->and($changes)->not->toHaveKey('id');
});

it('leaves passwords alone when password is null', function() {
    $rules = SanitizeRules::fromConfig(['password' => null], fakeSchema());

    expect($rules->tables['users'])->not->toHaveKey('password');
});

it('replaces custom field values by layout element UID and skips non-scalar values for formatters', function() {
    $content = [
        'uid-phone' => '+49 151 1234567',
        'uid-table' => [['col1' => 'Jane']],
        'uid-notes' => 'Call Jane',
        'uid-untouched' => 'keep me',
        'uid-empty' => '',
    ];

    $new = SanitizeRules::applyToContent($content, [
        'uid-phone' => 'phoneNumber',
        'uid-table' => 'name',
        'uid-notes' => fn(Generator $faker, $value) => 'redacted',
        'uid-empty' => 'word',
    ], Factory::create());

    expect($new['uid-phone'])->not->toBe('+49 151 1234567')
        ->and($new['uid-table'])->toBe([['col1' => 'Jane']])
        ->and($new['uid-notes'])->toBe('redacted')
        ->and($new['uid-untouched'])->toBe('keep me')
        ->and($new['uid-empty'])->toBe('')
        ->and(SanitizeRules::applyToContent(['other' => 'x'], ['uid-phone' => 'phoneNumber'], Factory::create()))->toBeNull();
});

it('keeps the public GraphQL token', function() {
    $rules = SanitizeRules::fromConfig([], fakeSchema());
    $faker = Factory::create();

    expect(SanitizeRules::applyToRow($rules->tables['gqltokens'], ['accessToken' => '__PUBLIC__'], $faker))->toBe(['accessToken' => '__PUBLIC__'])
        ->and(SanitizeRules::applyToRow($rules->tables['gqltokens'], ['accessToken' => 'secret'], $faker)['accessToken'])->not->toBe('secret');
});

it('empties the legacy content tables only where they are leftovers', function() {
    expect(SanitizeRules::fromConfig([], fakeSchema(legacyContent: true))->emptyTables)->toContain('content', 'matrixcontent_*')
        ->and(SanitizeRules::fromConfig([], fakeSchema(legacyContent: false))->emptyTables)->not->toContain('content');
});
