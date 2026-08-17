<?php

namespace Tests\Feature;

use App\Models\RunPackage;
use App\Models\PickupRoute;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Detalle de una jornada (CONTEXTO.md §7, fase 6.C).
 *
 * Los tests fijan **las obligaciones, no el maquetado**: cada fila de esta
 * pantalla es una acusación sobre una persona real, y lo que no puede pasar es
 * que una sospecha que el bot no sostiene se presente como un hecho.
 */
class IncidentRunScreenTest extends TestCase
{
    use RefreshDatabase, MakesIncidents;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
    }

    private function url(string $date = '2026-08-03'): string
    {
        return '/incidents/'.$date;
    }

    public function test_it_is_behind_the_session(): void
    {
        $this->storedRun();
        auth()->logout();

        $this->get($this->url())->assertRedirect('/login');
    }

    public function test_an_unknown_day_is_a_404(): void
    {
        $this->get($this->url('2026-01-01'))->assertNotFound();
    }

    /** Obligación 2: una jornada dudosa no cubre el día entero. */
    public function test_an_unreliable_run_warns_at_the_top(): void
    {
        $this->storedRun(reliable: false);

        $this->get($this->url())->assertOk()->assertSee('Esta corrida no es fiable');
    }

    public function test_a_reliable_run_does_not_warn(): void
    {
        $this->storedRun();

        $this->get($this->url())->assertOk()->assertDontSee('Esta corrida no es fiable');
    }

    /** El denominador honesto: sin él «168 incidencias» parece el día entero. */
    public function test_it_shows_what_was_left_out_of_the_analysis(): void
    {
        $this->storedRun();

        $this->get($this->url())
            ->assertOk()
            ->assertSee('490')
            ->assertSee('envíos de comercios');
    }

    /** Obligación 1: lo firme se ve primero y lo dudoso no se presenta igual. */
    public function test_a_low_confidence_finding_is_not_presented_like_a_firm_one(): void
    {
        $corrida = $this->storedRun();
        $this->incident($corrida, '1', confidence: RunPackage::CONFIDENCE_HIGH);
        $this->incident($corrida, '2', confidence: RunPackage::CONFIDENCE_LOW);

        $respuesta = $this->get($this->url())->assertOk();

        $respuesta->assertSee('Firme');
        $respuesta->assertSee('No concluyente');

        // El motivo, en palabras y no en clave.
        $respuesta->assertSee('esa ruta pasó desperdigada por la cinta ese día');
        $respuesta->assertDontSee('ruta_dispersa');
    }

    /**
     * El disclaimer de la columna «Fiabilidad». Sin él, la diferencia entre
     * firme y no concluyente queda a interpretación de quien lea la pantalla,
     * y de eso salen conversaciones con un mensajero.
     */
    public function test_it_explains_what_makes_a_finding_firm(): void
    {
        $this->storedRun();

        $this->get($this->url())
            ->assertOk()
            ->assertSee('La ruta pasó dispersa')
            ->assertSee('La tanda estaba compartida')
            ->assertSee('No concluyente no significa que no pasara nada');
    }

    /**
     * Los umbrales salen de la corrida, no escritos a mano: si el bot cambia
     * los suyos, el texto tiene que cambiar con ellos y no quedarse mintiendo.
     */
    public function test_the_thresholds_come_from_the_run_itself(): void
    {
        $corrida = $this->storedRun();
        $corrida->update(['tolerance_minutes' => 35, 'batch_gap_minutes' => 9]);

        $this->get($this->url())
            ->assertOk()
            ->assertSee('9 minutos')
            ->assertSee('35 minutos');
    }

    /** Retirada el 13/08/2026 a petición: el desglose por comercio no se pinta. */
    public function test_the_per_merchant_summary_is_gone(): void
    {
        $corrida = $this->storedRun();
        $this->incident($corrida, '1', confidence: RunPackage::CONFIDENCE_HIGH);

        $this->get($this->url())->assertOk()->assertDontSee('Lo que el bot sostiene');
    }

    /** Obligación 3: juntarlos convierte hechos neutros en acusaciones. */
    public function test_the_two_types_are_not_mixed(): void
    {
        $corrida = $this->storedRun();
        $this->incident($corrida, '1', type: RunPackage::TYPE_OTHER_ROUTE);
        $this->incident($corrida, '2', type: RunPackage::TYPE_OUT_OF_BATCH);

        $this->get($this->url())
            ->assertOk()
            ->assertSee('Se fueron con otra ruta')
            ->assertSee('Pasaron descolgados de su tanda')
            ->assertSee('no hay a quién señalar');
    }

    public function test_it_groups_by_the_route_that_owned_the_package(): void
    {
        $corrida = $this->storedRun();
        $this->incident($corrida, '1', assignedRoute: 'Ruta 3', courier: 'Freddy GLS');
        $this->incident($corrida, '2', assignedRoute: 'Ruta 6', courier: 'Vallecas');

        $this->get($this->url())
            ->assertOk()
            ->assertSee('Ruta 3')
            ->assertSee('Freddy GLS')
            ->assertSee('Ruta 6')
            ->assertSee('Vallecas');
    }

    /**
     * Obligación 4: los nombres copiados son la foto del día. Si renombrar una
     * ruta cambiase lo que dice una incidencia, el panel reescribiría el pasado.
     */
    public function test_renaming_a_route_does_not_rewrite_the_past(): void
    {
        $ruta = PickupRoute::create(['name' => 'Ruta 3']);
        $corrida = $this->storedRun();
        $this->incident($corrida, '1', assignedRoute: 'Ruta 3')->update(['assigned_route_id' => $ruta->id]);

        $ruta->update(['name' => 'Vallecas Sur']);

        $this->get($this->url())
            ->assertOk()
            ->assertSee('Ruta 3')
            ->assertDontSee('Vallecas Sur');
    }

    /** Obligación 5: existe para poder ver que algo estuvo, no para listarlo. */
    public function test_withdrawn_incidents_are_out_of_the_listing(): void
    {
        $corrida = $this->storedRun();
        $this->incident($corrida, '1', merchant: 'SIGUE AQUI SL');
        $this->incident($corrida, '2', merchant: 'YA NO VIENE SL')->update(['withdrawn_at' => now()]);

        $this->get($this->url())
            ->assertOk()
            ->assertSee('SIGUE AQUI SL')
            ->assertDontSee('YA NO VIENE SL');
    }

    /**
     * Las horas van en UTC porque es lo que muestran el informe del bot y GLS
     * Atlas. En Europe/Madrid saldrían dos horas corridas y el cliente no
     * podría contrastar ni un paquete contra el portal.
     */
    public function test_belt_times_are_shown_in_utc_like_gls_atlas(): void
    {
        $corrida = $this->storedRun();
        $this->incident($corrida, '1');

        $this->get($this->url())
            ->assertOk()
            ->assertSee('19:15:27')
            ->assertDontSee('21:15:27');
    }

    public function test_the_alerts_of_the_day_are_shown(): void
    {
        $this->storedRun(alerts: [
            ['tipo' => 'ruta_dispersa', 'texto' => 'Ruta 2: pasó DISPERSA por la cinta.'],
        ]);

        $this->get($this->url())->assertOk()->assertSee('Ruta 2: pasó DISPERSA por la cinta.');
    }

    /**
     * El bot antepone la fecha a cada alerta porque su informe cubre varios
     * días. Repetirla aquí ocho veces, en una página ya titulada con ella, es
     * ruido.
     */
    public function test_the_alerts_drop_the_date_the_page_already_carries(): void
    {
        $this->storedRun(alerts: [
            ['tipo' => 'ruta_dispersa', 'texto' => '2026-08-03 · Ruta 2: pasó DISPERSA.'],
        ]);

        $this->get($this->url())
            ->assertOk()
            ->assertSee('Ruta 2: pasó DISPERSA.')
            ->assertDontSee('2026-08-03 · Ruta 2');
    }

    /**
     * El detalle de una jornada sigue siendo «Incidencias»: si el menú se
     * cerrase al entrar, apagaría el enlace desde el que acabas de llegar.
     */
    public function test_the_sidebar_still_points_here_from_the_detail(): void
    {
        $this->storedRun();

        $this->get($this->url())
            ->assertOk()
            ->assertSee('abierto: true', escape: false)
            ->assertSee('Operaciones');
    }

    /** La cabecera no puede seguir diciendo «Maestro» fuera del maestro. */
    public function test_the_header_names_the_section_you_are_in(): void
    {
        $this->storedRun();

        $this->get($this->url())->assertOk()->assertDontSee('Maestro de rutas de recogida');
        $this->get('/merchants')->assertOk()->assertSee('Maestro de rutas de recogida');
    }

    /**
     * El resumen de traspasos, **y en qué dirección**.
     *
     * Antes esto sólo comprobaba que el título estuviese, y por eso pasó inadvertido que la
     * pantalla se leía al revés: pintaba «Ruta 3 → Ruta 1» bajo el rótulo «Quién recogió de
     * quién», que invita a entender que fue Ruta 3 quien recogió. Aquí el paquete era de
     * Ruta 3 y se lo llevó Ruta 1, y eso es lo que tiene que leerse.
     */
    public function test_it_summarises_who_picked_up_from_whom(): void
    {
        $corrida = $this->storedRun();
        $this->incident($corrida, '1', assignedRoute: 'Ruta 3', observedRoute: 'Ruta 1');
        $this->incident($corrida, '2', assignedRoute: 'Ruta 3', observedRoute: 'Ruta 1');

        $this->get($this->url())
            ->assertOk()
            ->assertSee('Paquetes que se llevó otra ruta')
            ->assertSeeInOrder(['Ruta 1', 'se llevó 2 de', 'Ruta 3']);
    }

    public function test_the_package_detail_opens_with_what_gls_atlas_needs(): void
    {
        $corrida = $this->storedRun();
        $incidencia = $this->incident($corrida, '1');

        \Livewire\Livewire::test('incident-run', ['date' => '2026-08-03'])
            ->call('show', $incidencia->id)
            ->assertSee('Código de barras')
            ->assertSee($incidencia->barcode)
            ->assertSee('Desvío sobre su ruta')
            ->call('cancel')
            ->assertDontSee('Código de barras');
    }

    /**
     * La mitad de la pregunta del cliente: no basta con las 11 incidencias de
     * la Ruta 1, hace falta saber que fueron sobre 94 paquetes. Once sobre 94
     * no es lo mismo que once sobre doce.
     */
    public function test_a_route_shows_the_packages_that_went_where_they_should(): void
    {
        $corrida = $this->storedRun();
        $this->incident($corrida, 'i1', assignedRoute: 'Ruta 1', courier: 'Benjamin GLS');
        foreach (range(1, 3) as $n) {
            $this->package($corrida, 'ok'.$n, merchant: 'BOHOCHIQUE', assignedRoute: 'Ruta 1', courier: 'Benjamin GLS');
        }

        $this->get($this->url())
            ->assertOk()
            ->assertSee('4 paquetes, 1 con incidencia')
            ->assertSee('Pasaron con su ruta (3)')
            ->assertSee('BOHOCHIQUE');
    }

    /**
     * Con un bot que sólo mande incidencias —como hasta el 13/08/2026— la
     * sección no aparece y la pantalla se queda como estaba. Un bot viejo no
     * puede romperse por un campo que no conoce.
     */
    public function test_without_the_full_day_the_section_is_not_shown(): void
    {
        $corrida = $this->storedRun();
        $this->incident($corrida, 'i1', assignedRoute: 'Ruta 1');

        $this->get($this->url())
            ->assertOk()
            ->assertDontSee('Pasaron con su ruta')
            ->assertDontSee('con incidencia');
    }

    /** Un paquete con ruta pero sin escanear: 34 el 03/08. No es incidencia. */
    public function test_a_package_without_a_belt_pass_says_so(): void
    {
        $corrida = $this->storedRun();
        $this->incident($corrida, 'i1', assignedRoute: 'Ruta 1');
        $this->package($corrida, 'ok1', assignedRoute: 'Ruta 1', beltTime: null);

        $this->get($this->url())->assertOk()->assertSee('sin paso por la cinta');
    }

    /**
     * Un paquete correcto no es «no concluyente»: no hay nada que concluir.
     * Decirlo de otra forma sembraría una duda que el bot no tiene.
     */
    public function test_the_detail_of_a_correct_package_does_not_cast_doubt(): void
    {
        $corrida = $this->storedRun();
        $paquete = $this->package($corrida, 'ok1');

        \Livewire\Livewire::test('incident-run', ['date' => '2026-08-03'])
            ->call('show', $paquete->id)
            ->assertSee('Pasó por la cinta con el grueso de su ruta')
            // Del modal, no del desplegable de arriba, que sí habla de ambas.
            ->assertDontSee('El bot <strong>no</strong> puede afirmar', escape: false)
            ->assertDontSee('Desvío sobre su ruta');
    }

    // --- Gestión de la incidencia (14/08/2026) --------------------------------

    public function test_it_saves_a_comment_without_closing_the_incident(): void
    {
        $corrida = $this->storedRun();
        $incidencia = $this->incident($corrida, '1');

        \Livewire\Livewire::test('incident-run', ['date' => '2026-08-03'])
            ->call('manage', $incidencia->id)
            ->set('note', 'Hablado con la UT: se confundió de jaula.')
            ->call('saveHandling')
            ->assertHasNoErrors();

        $incidencia->refresh();

        // Comentar no es atender: alguien la está mirando, y eso no es lo mismo
        // que haberla cerrado.
        $this->assertSame('Hablado con la UT: se confundió de jaula.', $incidencia->handling_note);
        $this->assertNull($incidencia->handled_at);
        $this->assertFalse($incidencia->isHandled());
    }

    public function test_marking_it_handled_records_who_and_when(): void
    {
        $corrida = $this->storedRun();
        $incidencia = $this->incident($corrida, '1');

        \Livewire\Livewire::test('incident-run', ['date' => '2026-08-03'])
            ->call('manage', $incidencia->id)
            ->set('handled', true)
            ->call('saveHandling');

        $incidencia->refresh();

        $this->assertTrue($incidencia->isHandled());
        $this->assertSame(auth()->id(), $incidencia->handled_by);

        // El nombre copiado, como en `audit_logs` (§4): la fila tiene que
        // leerse aunque quien la atendió se dé de baja.
        $this->assertSame(auth()->user()->fullName(), $incidencia->handled_by_name);
    }

    public function test_editing_the_comment_does_not_move_the_date_it_was_handled(): void
    {
        $corrida = $this->storedRun();
        $incidencia = $this->incident($corrida, '1');
        $incidencia->forceFill([
            'handled_at' => now()->subDays(3),
            'handled_by' => auth()->id(),
            'handled_by_name' => 'Quien fuera',
        ])->save();

        $cuando = $incidencia->handled_at;

        \Livewire\Livewire::test('incident-run', ['date' => '2026-08-03'])
            ->call('manage', $incidencia->id)
            ->set('note', 'Añado un detalle más.')
            ->call('saveHandling');

        // Al segundo: la columna es `timestampTz` sin decimales, como el resto
        // de las fechas de esta tabla.
        //
        // Si se moviera, la pantalla mentiría sobre cuánto se tardó en atenderla.
        $this->assertSame(
            $cuando->toDateTimeString(),
            $incidencia->refresh()->handled_at->toDateTimeString(),
        );
        $this->assertSame('Quien fuera', $incidencia->handled_by_name);
    }

    public function test_it_can_be_reopened(): void
    {
        $corrida = $this->storedRun();
        $incidencia = $this->incident($corrida, '1');
        $incidencia->forceFill([
            'handled_at' => now(),
            'handled_by' => auth()->id(),
            'handled_by_name' => 'Quien fuera',
        ])->save();

        \Livewire\Livewire::test('incident-run', ['date' => '2026-08-03'])
            ->call('manage', $incidencia->id)
            ->set('handled', false)
            ->call('saveHandling');

        $incidencia->refresh();

        // Sin rastro de una atención que ya no vale.
        $this->assertNull($incidencia->handled_at);
        $this->assertNull($incidencia->handled_by);
        $this->assertNull($incidencia->handled_by_name);
    }

    public function test_the_listing_says_whether_an_incident_was_handled(): void
    {
        $corrida = $this->storedRun();
        $this->incident($corrida, '1')->forceFill([
            'handled_at' => now(),
            'handled_by' => auth()->id(),
            'handled_by_name' => 'Quien mira',
        ])->save();
        $this->incident($corrida, '2');

        // Sin esto habría que abrirlas una a una para saber cuáles quedan.
        $this->get($this->url())->assertOk()
            ->assertSee('Atendida')
            ->assertSee('Pendiente')
            ->assertSee('Sin atender');
    }

    public function test_a_package_of_another_day_cannot_be_managed_from_here(): void
    {
        $this->storedRun();
        $otroDia = $this->incident($this->storedRun('2026-08-04'), '1');

        // El id llega del cliente: sin acotar por jornada se podría anotar
        // cualquier fila pasando otro número.
        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);

        \Livewire\Livewire::test('incident-run', ['date' => '2026-08-03'])
            ->call('manage', $otroDia->id);
    }

    public function test_a_comment_longer_than_the_column_is_rejected(): void
    {
        $corrida = $this->storedRun();
        $incidencia = $this->incident($corrida, '1');

        \Livewire\Livewire::test('incident-run', ['date' => '2026-08-03'])
            ->call('manage', $incidencia->id)
            ->set('note', str_repeat('a', 2001))
            ->call('saveHandling')
            ->assertHasErrors(['note' => 'max']);

        $this->assertNull($incidencia->refresh()->handling_note);
    }

    /** ~500 filas al día: una consulta para todas, no una por fila. */
    public function test_it_does_not_query_once_per_incident(): void
    {
        $corrida = $this->storedRun();
        foreach (range(1, 40) as $n) {
            $this->incident($corrida, (string) $n, assignedRoute: 'Ruta '.($n % 6 + 1));
        }

        $consultas = 0;
        \DB::listen(function () use (&$consultas) {
            $consultas++;
        });

        $this->get($this->url())->assertOk();

        $this->assertLessThan(10, $consultas, "El detalle disparó {$consultas} consultas.");
    }
}
