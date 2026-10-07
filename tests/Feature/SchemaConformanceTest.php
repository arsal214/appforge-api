<?php

namespace Tests\Feature;

use App\Support\Config\ConfigValidator;
use AppForge\Schema\Contract;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase as BaseTestCase;

/**
 * The shared conformance suite from `appforge-schema`.
 *
 * Generated constants stop the *vocabularies* diverging between languages.
 * They do nothing about two implementations reading the same list and doing
 * different things with it — which is the drift that actually bites, because
 * it looks like agreement right up until a config the panel accepts is
 * rejected on save.
 *
 * So the fixtures are the real contract test: the same files, the same expected
 * error paths, run by every language that validates a config. When the shell
 * grows its own validator it runs these too.
 *
 * Extends PHPUnit's TestCase directly — no database, no application boot. This
 * is a pure function over JSON and should fail in milliseconds.
 */
class SchemaConformanceTest extends BaseTestCase
{
    private static function fixturePath(string $relative = ''): string
    {
        return dirname(__DIR__, 2).'/../appforge-schema/fixtures/'.$relative;
    }

    /** @return array<string, mixed> */
    private static function manifest(): array
    {
        $path = self::fixturePath('index.json');

        return json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    }

    /** @return iterable<string, array{string, string}> */
    public static function validConfigs(): iterable
    {
        foreach (self::manifest()['valid'] as $case) {
            yield $case['file'] => [$case['file'], $case['why']];
        }
    }

    /** @return iterable<string, array{string, list<string>, string}> */
    public static function invalidConfigs(): iterable
    {
        foreach (self::manifest()['invalid'] as $case) {
            yield $case['file'] => [$case['file'], $case['errorPaths'], $case['why']];
        }
    }

    /** @return array<string, mixed> */
    private function load(string $file): array
    {
        $raw = file_get_contents(self::fixturePath($file));
        $this->assertNotFalse($raw, "Missing fixture: {$file}");

        $decoded = json_decode((string) $raw, true, 512, JSON_THROW_ON_ERROR);
        // Fixtures carry $comment keys explaining what they are for. They are
        // documentation, not payload, and the validator would rightly refuse
        // them as unexpected fields.
        unset($decoded['$comment']);

        return $decoded;
    }

    #[Test]
    #[DataProvider('validConfigs')]
    public function a_valid_fixture_is_accepted(string $file, string $why): void
    {
        $errors = (new ConfigValidator)->validate($this->load($file));

        $this->assertSame(
            [],
            $errors,
            "{$file} should validate. {$why}\nGot: ".json_encode($errors, JSON_PRETTY_PRINT),
        );
    }

    #[Test]
    #[DataProvider('invalidConfigs')]
    public function an_invalid_fixture_is_rejected_at_the_expected_path(
        string $file,
        array $expectedPaths,
        string $why,
    ): void {
        $errors = (new ConfigValidator)->validate($this->load($file));

        $this->assertNotSame([], $errors, "{$file} should be rejected. {$why}");

        foreach ($expectedPaths as $path) {
            $this->assertArrayHasKey(
                $path,
                $errors,
                "{$file} should report an error at [{$path}]. {$why}\n".
                'Reported: '.implode(', ', array_keys($errors)),
            );
        }
    }

    #[Test]
    public function the_generated_contract_is_the_one_this_repo_validates_against(): void
    {
        // Guards against the failure mode this whole sprint exists to remove:
        // a vocabulary edited in one language and not the other. If these ever
        // disagree, someone has hand-edited generated code.
        $contract = json_decode(
            (string) file_get_contents(dirname(__DIR__, 2).'/../appforge-schema/contract.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        $this->assertSame($contract['schemaVersion'], Contract::SCHEMA_VERSION);
        $this->assertSame(
            $contract['vocabularies']['actionTypes'],
            Contract::vocabulary('actionTypes'),
        );
        $this->assertSame(
            $contract['vocabularies']['sectionTypes'],
            Contract::vocabulary('sectionTypes'),
        );
        $this->assertSame(
            $contract['fields']['sectionProps']['hero'],
            Contract::fields('sectionProps.hero'),
        );
    }
}
