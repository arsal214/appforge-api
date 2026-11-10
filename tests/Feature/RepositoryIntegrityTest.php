<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Fails if the malware that was force-pushed into this repository on 6
 * November 2026 comes back.
 *
 * It arrived as a commit impersonating a real one — same author, same subject,
 * same timestamp, different hash — so nothing in the history looked unusual.
 * What it added:
 *
 *   - a file named `init`, a short harmless preamble then one obfuscated line
 *   - `node init` wired into the package.json dev and build scripts, so it ran
 *     on every `npm run dev` and `npm run build`
 *   - the same blob appended to vite/postcss/babel config, so deleting `init`
 *     alone would not have stopped it
 *   - `.env` and `.env.production` removed from .gitignore, so secrets would be
 *     committed and pushed to a public repository
 *   - in appforge-schema, a dropper that base64-decodes a URL out of `.env`,
 *     fetches it and `eval`s whatever comes back
 *
 * This test is cheap and it runs with every other gate. It will not stop a
 * determined attacker — only securing the account does that — but it does mean
 * a re-infection cannot pass a green test run unnoticed.
 */
class RepositoryIntegrityTest extends TestCase
{
    /** Built at runtime so this file does not match its own search. */
    private function needles(): array
    {
        return [
            'AUTH_'.'API_KEY' => 'the dropper reads a URL out of this variable',
            'cat'.'box.moe' => 'the host the dropper fetched its payload from',
            'node '.'init' => 'the hook that ran the payload on dev and build',
        ];
    }

    #[Test]
    public function no_init_dropper_is_present(): void
    {
        foreach (['init', 'config.bat'] as $name) {
            $this->assertFileDoesNotExist(
                base_path($name),
                "[{$name}] is part of the 6 November malware. Do not run anything in this ".
                'repository until it is removed and the account is secured.',
            );
        }
    }

    #[Test]
    public function no_file_carries_the_malware_markers(): void
    {
        $roots = ['app', 'config', 'routes', 'database', 'resources', 'bootstrap', 'public'];
        $found = [];

        foreach ($this->needles() as $needle => $why) {
            foreach ($roots as $root) {
                $directory = base_path($root);

                if (! is_dir($directory)) {
                    continue;
                }

                $files = new \RecursiveIteratorIterator(
                    new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
                );

                foreach ($files as $file) {
                    if (! $file->isFile() || $file->getSize() > 2_000_000) {
                        continue;
                    }

                    $contents = @file_get_contents($file->getPathname());

                    if ($contents !== false && str_contains($contents, $needle)) {
                        $found[] = $file->getPathname()." ({$why})";
                    }
                }
            }
        }

        $this->assertSame([], $found, "Malware markers found:\n".implode("\n", $found));
    }

    #[Test]
    public function the_env_files_are_still_ignored(): void
    {
        // The attack removed these so the secrets would be committed. This
        // repository is public, so that is the whole exfiltration route.
        $ignore = @file_get_contents(base_path('.gitignore')) ?: '';

        foreach (['.env', '.env.production'] as $entry) {
            $this->assertMatchesRegularExpression(
                '/^'.preg_quote($entry, '/').'$/m',
                $ignore,
                "[{$entry}] is no longer ignored, which is how the secrets were published.",
            );
        }
    }

    #[Test]
    public function the_npm_scripts_run_only_what_they_claim(): void
    {
        $path = base_path('package.json');

        if (! is_file($path)) {
            $this->markTestSkipped('No package.json in this repository.');
        }

        $scripts = json_decode((string) file_get_contents($path), true)['scripts'] ?? [];

        foreach ($scripts as $name => $command) {
            $this->assertStringNotContainsString(
                'init',
                (string) $command,
                "The [{$name}] script runs something called init. That is how the payload was started.",
            );
        }
    }
}
