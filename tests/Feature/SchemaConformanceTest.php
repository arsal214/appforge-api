<?php

namespace Tests\Feature;

use App\Support\Config\ConfigValidator;
use AppForge\Schema\Contract;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase as BaseTestCase;

class SchemaConformanceTest extends BaseTestCase
{
    private static function fixturePath(string $relative = ''): string
    {
        return dirname(__DIR__, 2).'/../appforge-schema/fixtures/'.$relative;
    }

    private static function manifest(): array
    {
        $path = self::fixturePath('index.json');

        return json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    }

    public static function validConfigs(): iterable
    {
        foreach (self::manifest()['valid'] as $case) {
            yield $case['file'] => [$case['file'], $case['why']];
        }
    }

    public static function invalidConfigs(): iterable
    {
        foreach (self::manifest()['invalid'] as $case) {
            yield $case['file'] => [$case['file'], $case['errorPaths'], $case['why']];
        }
    }

    private function load(string $file): array
    {
        $raw = file_get_contents(self::fixturePath($file));
        $this->assertNotFalse($raw, "Missing fixture: {$file}");

        $decoded = json_decode((string) $raw, true, 512, JSON_THROW_ON_ERROR);

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
