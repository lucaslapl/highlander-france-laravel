<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Téléchargements privés : liens /dl/{token} (ZIP volumineux hors webroot).
 */
class DownloadControllerTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = '0123456789abcdef0123456789abcdef';

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = storage_path('app/hlfr/testing-downloads');
        File::deleteDirectory($this->dir);

        $this->app['config']->set('hlfr.downloads.dir', $this->dir);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);

        parent::tearDown();
    }

    private function writeFile(string $token, string $name = 'archive.zip', string $content = 'contenu'): string
    {
        $dir = $this->dir.'/'.$token;
        File::makeDirectory($dir, 0775, true, true);

        $path = $dir.'/'.$name;
        File::put($path, $content);

        return $path;
    }

    /**
     * Vérifie que la directive Cache-Control est présente (l'ordre des
     * directives est réordonné par Symfony).
     *
     * @param  list<string>  $directives
     */
    private function assertCacheControl(TestResponse $response, array $directives): void
    {
        $cacheControl = (string) $response->headers->get('Cache-Control');

        foreach ($directives as $directive) {
            $this->assertStringContainsString($directive, $cacheControl);
        }
    }

    // ─── Accès par la route ───────────────────────────────────────────────

    public function test_un_token_valide_sert_le_fichier_en_telechargement(): void
    {
        $path = $this->writeFile(self::TOKEN, 'archives-demo.zip', 'contenu du zip');

        try {
            $response = $this->get('/dl/'.self::TOKEN);

            $response->assertOk();
            $response->assertHeader('Content-Disposition', 'attachment; filename=archives-demo.zip');
            $response->assertHeader('Content-Type', 'application/octet-stream');
            $response->assertHeader('Content-Length', (string) filesize($path));
            $this->assertCacheControl($response, ['private', 'no-store', 'must-revalidate']);
        } finally {
            @unlink($path);
        }
    }

    public function test_un_token_inconnu_repond_404(): void
    {
        $this->get('/dl/fedcba9876543210fedcba9876543210')->assertNotFound();
    }

    public function test_un_token_au_format_invalide_repond_404(): void
    {
        $this->get('/dl/NOT-A-TOKEN')->assertNotFound();
        $this->get('/dl/'.strtoupper(self::TOKEN))->assertNotFound();
    }

    public function test_un_dossier_sans_fichier_autorise_repond_404(): void
    {
        $path = $this->writeFile(self::TOKEN, 'note.txt', 'pas un zip');

        try {
            $this->get('/dl/'.self::TOKEN)->assertNotFound();
        } finally {
            @unlink($path);
        }
    }

    public function test_le_mode_xsendfile_delegue_l_envoi_a_apache(): void
    {
        $path = $this->writeFile(self::TOKEN);

        try {
            $this->app['config']->set('hlfr.downloads.xsendfile', true);

            $response = $this->get('/dl/'.self::TOKEN);

            $response->assertOk();
            $response->assertHeader('X-Sendfile', $path);
            $response->assertHeader('Content-Length', (string) filesize($path));
            $this->assertCacheControl($response, ['private', 'no-store', 'must-revalidate']);
            $response->assertNoContent(200);
        } finally {
            @unlink($path);
        }
    }

    // ─── Commandes artisan ────────────────────────────────────────────────

    public function test_la_commande_add_enregistre_le_fichier_et_affiche_le_lien(): void
    {
        $source = storage_path('app/testing-downloads-source.zip');
        File::put($source, 'contenu du zip');
        $basename = basename($source);

        try {
            $this->artisan('app:downloads:add', ['source' => $source])
                ->expectsOutputToContain('/dl/')
                ->assertExitCode(0);

            $tokens = array_filter(glob($this->dir.'/*'), 'is_dir');
            $this->assertCount(1, $tokens);
            $this->assertFileExists($tokens[0].'/'.$basename);
            $this->assertFileDoesNotExist($source);
        } finally {
            File::deleteDirectory($this->dir);
            @unlink($source);
        }
    }

    public function test_la_commande_add_refuse_un_fichier_manquant_ou_une_extension_etrangere(): void
    {
        $this->artisan('app:downloads:add', ['source' => '/inexistant.zip'])->assertExitCode(1);

        $source = storage_path('app/testing-downloads-source.txt');
        File::put($source, 'pas un zip');

        try {
            $this->artisan('app:downloads:add', ['source' => $source])->assertExitCode(1);
        } finally {
            @unlink($source);
        }
    }

    public function test_la_commande_revoke_supprime_le_token(): void
    {
        $this->writeFile(self::TOKEN);

        $this->artisan('app:downloads:revoke', ['token' => self::TOKEN])->assertExitCode(0);
        $this->assertDirectoryDoesNotExist($this->dir.'/'.self::TOKEN);

        $this->artisan('app:downloads:revoke', ['token' => self::TOKEN])->assertExitCode(1);
        $this->artisan('app:downloads:revoke', ['token' => 'invalide'])->assertExitCode(1);
    }

    public function test_la_commande_list_affiche_les_tokens(): void
    {
        $this->writeFile(self::TOKEN, 'archives-demo.zip', 'contenu');

        $exitCode = Artisan::call('app:downloads:list');
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString(self::TOKEN, $output);
        $this->assertStringContainsString('archives-demo.zip', $output);
    }
}
