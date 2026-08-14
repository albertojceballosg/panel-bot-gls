<?php

namespace Tests\Feature;

use App\Models\Courier;
use App\Models\User;
use App\Support\DatabaseBackup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Process;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Copias de seguridad (CONTEXTO.md §7, fase 7).
 *
 * **Aquí no se restaura de verdad.** Un `pg_restore` sobre la base de test la
 * dejaría sin las tablas que el propio test necesita para terminar, así que lo
 * que se fija es el mandato que se construye y las cautelas de la pantalla. El
 * volcado sí se hace de verdad —`pg_dump` sólo lee— y hay un test que comprueba
 * que lo que se descarga es un volcado que `pg_restore` reconoce.
 */
class BackupsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create());
    }

    /** El mandato tal cual se lanzó: se pasa como array, no como cadena. */
    private function linea($proceso): string
    {
        return implode(' ', (array) $proceso->command);
    }

    /** Un fichero con la cabecera de un volcado en formato `custom`. */
    private function copia(string $nombre = 'panel-2026-08-13-120000.dump'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($nombre, "PGDMP\x00".str_repeat('x', 200));
    }

    public function test_it_is_behind_the_session(): void
    {
        auth()->logout();

        $this->get('/backups')->assertRedirect('/login');
        $this->get('/backups/download')->assertRedirect('/login');
    }

    // --- Descargar ------------------------------------------------------------

    public function test_it_downloads_a_real_dump_of_the_database(): void
    {
        Courier::create(['name' => 'Freddy GLS']);

        $respuesta = $this->get('/backups/download')->assertOk();

        $this->assertStringContainsString('attachment;', $respuesta->headers->get('content-disposition'));
        $this->assertMatchesRegularExpression('/panel-\d{4}-\d{2}-\d{2}-\d{6}\.dump/', $respuesta->headers->get('content-disposition'));

        // Que sea un volcado de verdad y no un fichero vacío con buen nombre.
        $contenido = $respuesta->streamedContent();
        $this->assertStringStartsWith('PGDMP', $contenido);
        $this->assertGreaterThan(1000, strlen($contenido));

        // Y que `pg_restore` lo sepa leer, que es para lo que existe. `--list`
        // lo enumera sin tocar ninguna base.
        $fichero = tempnam(sys_get_temp_dir(), 'test-dump-');
        file_put_contents($fichero, $contenido);

        $listado = Process::run(['pg_restore', '--list', $fichero]);
        unlink($fichero);

        $this->assertTrue($listado->successful(), $listado->errorOutput());
        $this->assertStringContainsString('couriers', $listado->output());
    }

    public function test_the_download_leaves_nothing_behind_on_the_server(): void
    {
        $antes = glob(sys_get_temp_dir().'/panel-dump-*') ?: [];

        $this->get('/backups/download')->assertOk()->streamedContent();

        // El temporal se borra al terminar el envío: en el servidor no puede
        // quedarse una copia con los datos del cliente dentro (§9).
        $this->assertSame($antes, glob(sys_get_temp_dir().'/panel-dump-*') ?: []);
    }

    public function test_a_failed_dump_says_why_instead_of_downloading_nothing(): void
    {
        Process::fake(['*pg_dump*' => Process::result(errorOutput: 'pg_dump: error: connection to server failed', exitCode: 1)]);

        $this->get('/backups/download')
            ->assertRedirect('/backups')
            ->assertSessionHas('error', fn (string $error) => str_contains($error, 'connection to server failed'));
    }

    // --- Restaurar ------------------------------------------------------------

    public function test_it_only_accepts_a_file_that_is_a_postgres_dump(): void
    {
        Process::fake();

        Livewire::test('backups')
            ->set('upload', UploadedFile::fake()->createWithContent('vacaciones.jpg', 'esto no es un volcado'))
            ->call('confirmRestore')
            ->assertHasErrors('upload')
            ->assertSet('confirming', false);

        Process::assertNotRan(fn ($proceso) => str_contains($this->linea($proceso), 'pg_restore'));
    }

    public function test_it_asks_before_replacing_the_database(): void
    {
        Process::fake();

        Livewire::test('backups')
            ->set('upload', $this->copia())
            ->call('confirmRestore')
            ->assertHasNoErrors()
            ->assertSet('confirming', true)
            ->assertSee('Escribe RESTAURAR');

        // Preguntar no es restaurar.
        Process::assertNotRan(fn ($proceso) => str_contains($this->linea($proceso), 'pg_restore'));
    }

    public function test_restoring_needs_the_word_typed(): void
    {
        Process::fake();

        Livewire::test('backups')
            ->set('upload', $this->copia())
            ->call('confirmRestore')
            ->set('confirmation', 'restaurar')   // Sin mayúsculas no vale.
            ->call('restore')
            ->assertHasErrors('confirmation');

        Process::assertNotRan(fn ($proceso) => str_contains($this->linea($proceso), 'pg_restore'));
        $this->assertAuthenticated();
    }

    public function test_it_restores_the_uploaded_file(): void
    {
        Process::fake();

        Livewire::test('backups')
            ->set('upload', $this->copia())
            ->call('confirmRestore')
            ->set('confirmation', 'RESTAURAR')
            ->call('restore')
            ->assertRedirect('/login');

        // Limpia antes de escribir y todo dentro de una transacción: si algo
        // falla a mitad, la base se queda como estaba.
        Process::assertRan(fn ($proceso) => str_contains($this->linea($proceso), 'pg_restore')
            && str_contains($this->linea($proceso), '--clean')
            && str_contains($this->linea($proceso), '--if-exists')
            && str_contains($this->linea($proceso), '--single-transaction')
            && str_contains($this->linea($proceso), '--exit-on-error'));
    }

    public function test_restoring_ends_the_session(): void
    {
        Process::fake();

        Livewire::test('backups')
            ->set('upload', $this->copia())
            ->call('confirmRestore')
            ->set('confirmation', 'RESTAURAR')
            ->call('restore')
            ->assertRedirect('/login');

        // Las sesiones viven en la base: la de quien restaura ya no existe.
        $this->assertGuest();
    }

    public function test_a_failed_restore_says_so_and_keeps_the_session(): void
    {
        Process::fake([
            '*pg_restore*' => Process::result(errorOutput: 'pg_restore: error: could not read from input file', exitCode: 1),
            '*pg_dump*' => Process::result(),
        ]);

        Livewire::test('backups')
            ->set('upload', $this->copia())
            ->call('confirmRestore')
            ->set('confirmation', 'RESTAURAR')
            ->call('restore')
            ->assertNoRedirect()
            ->assertDispatched('toast', fn ($nombre, $params) => $params['type'] === 'error'
                && str_contains($params['message'], 'could not read from input file'));

        $this->assertAuthenticated();
    }

    // --- La pantalla -----------------------------------------------------------

    public function test_it_warns_when_the_tools_are_missing(): void
    {
        Process::fake(['*pg_dump*' => Process::result(exitCode: 127)]);

        Livewire::test('backups')->assertSee('Faltan');
    }

    public function test_the_screen_says_the_panel_keeps_no_copies(): void
    {
        // No es un adorno: si el panel no guarda nada, la copia buena es la que
        // se lleva el usuario, y eso cambia lo que tiene que hacer.
        Livewire::test('backups')->assertSee('el panel no guarda ninguno');
    }

    public function test_the_dump_does_not_carry_the_password_in_the_command_line(): void
    {
        Process::fake();

        app(DatabaseBackup::class)->dump();

        // Lo que va en la línea de mandato lo ve cualquiera que liste los
        // procesos de la máquina (§10): la contraseña va por entorno.
        Process::assertRan(fn ($proceso) => ! str_contains(
            $this->linea($proceso),
            (string) config('database.connections.'.config('database.default').'.password'),
        ));
    }
}
