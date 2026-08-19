<?php

namespace Tests\Feature;

use App\Models\PickupRoute;
use App\Models\RunPackage;
use App\Models\User;
use App\Support\PermissionCatalog;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
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
    use MakesIncidents, RefreshDatabase;

    /** Quien mira la jornada: hace falta su nombre para lo que firma al atender. */
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->actingAs($this->user);
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

        Livewire::test('incident-run', ['date' => '2026-08-03'])
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

        Livewire::test('incident-run', ['date' => '2026-08-03'])
            ->call('show', $paquete->id)
            ->assertSee('Pasó por la cinta con el grueso de su ruta')
            // Del modal, no del desplegable de arriba, que sí habla de ambas.
            ->assertDontSee('El bot <strong>no</strong> puede afirmar', escape: false)
            ->assertDontSee('Desvío sobre su ruta');
    }

    // --- Gestión de la incidencia (14/08/2026) --------------------------------

    public function test_a_comment_cannot_be_saved_leaving_the_incident_open(): void
    {
        // Regla del 18/08/2026: el comentario dice *qué se hizo*, así que una
        // incidencia comentada y sin atender era un estado que no significaba
        // nada. Hasta ese día se guardaba y el listado lo pintaba como «Con
        // comentario».
        $corrida = $this->storedRun();
        $incidencia = $this->incident($corrida, '1');

        Livewire::test('incident-run', ['date' => '2026-08-03'])
            ->call('manage', $incidencia->id)
            ->set('note', 'Hablado con la UT: se confundió de jaula.')
            ->call('saveHandling')
            ->assertHasErrors('handled');

        $incidencia->refresh();

        $this->assertNull($incidencia->handling_note);
        $this->assertNull($incidencia->handled_at);
    }

    public function test_the_comment_is_saved_together_with_the_closing(): void
    {
        $corrida = $this->storedRun();
        $incidencia = $this->incident($corrida, '1');

        Livewire::test('incident-run', ['date' => '2026-08-03'])
            ->call('manage', $incidencia->id)
            ->set('note', 'Hablado con la UT: se confundió de jaula.')
            ->set('handled', true)
            ->call('saveHandling')
            ->assertHasNoErrors();

        $incidencia->refresh();

        $this->assertSame('Hablado con la UT: se confundió de jaula.', $incidencia->handling_note);
        $this->assertTrue($incidencia->isHandled());
    }

    public function test_closing_without_a_comment_is_still_legitimate(): void
    {
        // Lo que no cambió: exigir un comentario para cerrar sólo produce
        // comentarios que dicen «ok».
        $corrida = $this->storedRun();
        $incidencia = $this->incident($corrida, '1');

        Livewire::test('incident-run', ['date' => '2026-08-03'])
            ->call('manage', $incidencia->id)
            ->set('handled', true)
            ->call('saveHandling')
            ->assertHasNoErrors();

        $this->assertTrue($incidencia->refresh()->isHandled());
        $this->assertNull($incidencia->handling_note);
    }

    public function test_reopening_an_incident_wipes_its_comment_too(): void
    {
        // Decía qué se hizo, y ya no hay nada hecho. Se borra sin preguntar y
        // aunque el campo siga lleno: si no, reabrir obligaría a vaciar el
        // texto a mano y chocaría con la regla de arriba.
        $corrida = $this->storedRun();
        $incidencia = $this->incident($corrida, '1');
        $incidencia->forceFill([
            'handled_at' => now(),
            'handled_by' => auth()->id(),
            'handled_by_name' => 'Quien fuera',
            'handling_note' => 'Hablado con la UT.',
        ])->save();

        Livewire::test('incident-run', ['date' => '2026-08-03'])
            ->call('manage', $incidencia->id)
            ->assertSet('note', 'Hablado con la UT.')
            ->set('handled', false)
            ->call('saveHandling')
            ->assertHasNoErrors();

        $incidencia->refresh();

        $this->assertNull($incidencia->handling_note);
        $this->assertNull($incidencia->handled_at);
    }

    public function test_marking_it_handled_records_who_and_when(): void
    {
        $corrida = $this->storedRun();
        $incidencia = $this->incident($corrida, '1');

        Livewire::test('incident-run', ['date' => '2026-08-03'])
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

        Livewire::test('incident-run', ['date' => '2026-08-03'])
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

        Livewire::test('incident-run', ['date' => '2026-08-03'])
            ->call('manage', $incidencia->id)
            ->set('handled', false)
            ->call('saveHandling');

        $incidencia->refresh();

        // Sin rastro de una atención que ya no vale.
        $this->assertNull($incidencia->handled_at);
        $this->assertNull($incidencia->handled_by);
        $this->assertNull($incidencia->handled_by_name);
    }

    public function test_saving_without_changing_anything_says_so(): void
    {
        // Antes cerraba el diálogo con un «Incidencia actualizada» que no era
        // verdad: no se había tocado nada.
        $corrida = $this->storedRun();
        $incidencia = $this->incident($corrida, '1');

        Livewire::test('incident-run', ['date' => '2026-08-03'])
            ->call('manage', $incidencia->id)
            ->call('saveHandling')
            ->assertHasErrors('handled')
            ->assertSee('No has cambiado nada')
            // Y el diálogo se queda abierto, con la incidencia como estaba.
            ->assertSet('managing', $incidencia->id);

        $this->assertNull($incidencia->refresh()->handled_at);
    }

    public function test_a_batch_without_a_comment_and_without_closing_says_so(): void
    {
        $corrida = $this->storedRun();
        $incidencia = $this->incident($corrida, '1');

        Livewire::test('incident-run', ['date' => '2026-08-03'])
            ->call('manageSelection', [(string) $incidencia->id])
            ->call('saveSelection')
            ->assertHasErrors('handled')
            ->assertSet('bulk', true);
    }

    public function test_the_comment_has_a_length_and_its_message_names_the_field(): void
    {
        // «El campo note» no lo entiende nadie: el mensaje se lee en pantalla.
        $corrida = $this->storedRun();
        $incidencia = $this->incident($corrida, '1');

        Livewire::test('incident-run', ['date' => '2026-08-03'])
            ->call('manage', $incidencia->id)
            ->set('note', str_repeat('a', 2001))
            ->set('handled', true)
            ->call('saveHandling')
            ->assertHasErrors(['note' => 'max'])
            ->assertSee('comentario')
            ->assertDontSee('El campo note');

        $this->assertNull($incidencia->refresh()->handling_note);
    }

    public function test_the_dialog_says_that_the_two_go_together(): void
    {
        $corrida = $this->storedRun();
        $incidencia = $this->incident($corrida, '1');

        Livewire::test('incident-run', ['date' => '2026-08-03'])
            ->call('manage', $incidencia->id)
            ->assertSee('Guardar el comentario')
            ->assertSee('se borrará también el comentario');
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
        $this->expectException(ModelNotFoundException::class);

        Livewire::test('incident-run', ['date' => '2026-08-03'])
            ->call('manage', $otroDia->id);
    }

    public function test_a_comment_longer_than_the_column_is_rejected(): void
    {
        $corrida = $this->storedRun();
        $incidencia = $this->incident($corrida, '1');

        Livewire::test('incident-run', ['date' => '2026-08-03'])
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

    // --- El resalte por UT, desde el calendario de capacidades (18/08/2026) ---

    /**
     * La sección de esa ruta, como la ve la vista.
     *
     * Sale de la propiedad calculada y no de `viewData`: desde el 18/08/2026 el
     * listado es una isla y sus datos ya no viajan en `with()`, justo para no
     * cargarlos cuando sólo se abre un diálogo.
     */
    private function seccion($componente, string $ruta): ?array
    {
        return collect($componente->instance()->rutas)->firstWhere('nombre', $ruta);
    }

    public function test_it_highlights_the_routes_of_the_courier_it_was_opened_with(): void
    {
        $corrida = $this->storedRun();
        $this->incident($corrida, '1', assignedRoute: 'Ruta 3', courier: 'Freddy GLS');
        $this->incident($corrida, '2', assignedRoute: 'Ruta 1', courier: 'Otro');

        $componente = Livewire::withQueryParams(['ut' => 'Freddy GLS'])
            ->test('incident-run', ['date' => '2026-08-03']);

        $this->assertTrue($this->seccion($componente, 'Ruta 3')['destacada']);
        $this->assertFalse($this->seccion($componente, 'Ruta 1')['destacada']);

        $componente->assertSee('Resaltando las rutas de')->assertSee('Freddy GLS');
    }

    public function test_without_the_parameter_nothing_is_highlighted(): void
    {
        $corrida = $this->storedRun();
        $this->incident($corrida, '1', assignedRoute: 'Ruta 3', courier: 'Freddy GLS');

        $componente = Livewire::test('incident-run', ['date' => '2026-08-03']);

        $this->assertFalse($this->seccion($componente, 'Ruta 3')['destacada']);
        $componente->assertDontSee('Resaltando las rutas de');
    }

    public function test_a_courier_without_routes_that_day_is_told_so(): void
    {
        // Sin el aviso, un resalte que no aparece se lee como una pantalla rota.
        $corrida = $this->storedRun();
        $this->incident($corrida, '1', assignedRoute: 'Ruta 3', courier: 'Freddy GLS');

        Livewire::withQueryParams(['ut' => 'Quien libraba'])
            ->test('incident-run', ['date' => '2026-08-03'])
            ->assertSee('no llevaba ninguna ruta en esta jornada');
    }

    public function test_the_routes_nobody_was_driving_are_highlighted_with_the_sentinel(): void
    {
        // En la fila «Sin UT asignada» del calendario no hay nombre que poner en
        // la URL, y un `?ut=` vacío es «sin filtro».
        $corrida = $this->storedRun();
        $this->incident($corrida, '1', assignedRoute: 'Ruta 3', courier: null);
        $this->incident($corrida, '2', assignedRoute: 'Ruta 1', courier: 'Freddy GLS');

        $componente = Livewire::withQueryParams(['ut' => 'sin-ut'])
            ->test('incident-run', ['date' => '2026-08-03']);

        $this->assertTrue($this->seccion($componente, 'Ruta 3')['destacada']);
        $this->assertFalse($this->seccion($componente, 'Ruta 1')['destacada']);
        $componente->assertSee('Sin UT asignada');
    }

    public function test_the_highlight_can_be_dropped_without_leaving_the_day(): void
    {
        $corrida = $this->storedRun();
        $this->incident($corrida, '1', assignedRoute: 'Ruta 3', courier: 'Freddy GLS');

        $componente = Livewire::withQueryParams(['ut' => 'Freddy GLS'])
            ->test('incident-run', ['date' => '2026-08-03'])
            ->call('clearCourier')
            ->assertSet('courier', '');

        $this->assertFalse($this->seccion($componente, 'Ruta 3')['destacada']);
    }

    // --- Gestionar es escribir (§7, fase 12) ---------------------------------

    public function test_reading_a_day_does_not_let_you_manage_its_incidents(): void
    {
        $corrida = $this->storedRun();
        $incidencia = $this->incident($corrida, '1');

        // Sólo `incidents.view`: entra a la jornada, pero comentarla y darla por
        // atendida es otro permiso, y a estos métodos se llega desde el
        // navegador aunque el Blade no pinte el botón.
        $mirona = User::factory()->withoutRole()->create();
        $mirona->givePermissionTo('incidents.view');
        $this->actingAs($mirona);

        $this->get($this->url())->assertOk()->assertDontSee('Comentar o marcar como atendida');

        Livewire::test('incident-run', ['date' => '2026-08-03'])
            ->call('manage', $incidencia->id)
            ->assertForbidden();

        Livewire::test('incident-run', ['date' => '2026-08-03'])
            ->set('handled', true)
            ->call('saveHandling')
            ->assertForbidden();

        $this->assertNull($incidencia->refresh()->handled_at);
    }

    public function test_with_the_manage_permission_it_still_works(): void
    {
        $corrida = $this->storedRun();
        $incidencia = $this->incident($corrida, '1');

        $this->actingAs(User::factory()->role(PermissionCatalog::ROLE_OPERATIONS)->create());

        Livewire::test('incident-run', ['date' => '2026-08-03'])
            ->call('manage', $incidencia->id)
            ->set('handled', true)
            ->call('saveHandling')
            ->assertHasNoErrors();

        $this->assertNotNull($incidencia->refresh()->handled_at);
    }

    // --- Gestión en lote (18/08/2026) ----------------------------------------

    public function test_it_closes_several_incidents_with_one_comment(): void
    {
        // El caso real: un lote del mismo comercio pasa por la cinta en
        // segundos y arrastra exactamente la misma incidencia.
        $corrida = $this->storedRun();
        $lote = collect(['1', '2', '3'])->map(fn ($id) => $this->incident($corrida, $id));

        Livewire::test('incident-run', ['date' => '2026-08-03'])
            ->set('selection', $lote->pluck('id')->all())
            ->call('manageSelection')
            ->assertSet('bulk', true)
            ->set('note', 'Mismo lote: pasaron seguidos por la cinta.')
            ->set('handled', true)
            ->call('saveSelection')
            ->assertHasNoErrors()
            ->assertSet('selection', [])
            ->assertSet('bulk', false);

        foreach ($lote as $incidencia) {
            $incidencia->refresh();

            $this->assertSame('Mismo lote: pasaron seguidos por la cinta.', $incidencia->handling_note);
            $this->assertNotNull($incidencia->handled_at);
            $this->assertSame($this->user->fullName(), $incidencia->handled_by_name);
        }
    }

    public function test_an_empty_comment_in_bulk_does_not_wipe_the_ones_already_written(): void
    {
        // En el diálogo de una sola, vaciar el campo es querer quitar el
        // comentario. Aquí sería un accidente: se marcan quince y se cierran.
        $corrida = $this->storedRun();
        $conComentario = $this->incident($corrida, '1');
        $conComentario->handling_note = 'Hablado con la UT';
        $conComentario->save();
        $sinComentario = $this->incident($corrida, '2');

        Livewire::test('incident-run', ['date' => '2026-08-03'])
            ->set('selection', [$conComentario->id, $sinComentario->id])
            ->call('manageSelection')
            ->set('handled', true)
            ->call('saveSelection')
            ->assertHasNoErrors();

        $this->assertSame('Hablado con la UT', $conComentario->refresh()->handling_note);
        $this->assertNull($sinComentario->refresh()->handling_note);
        $this->assertNotNull($sinComentario->handled_at);
    }

    public function test_bulk_closes_but_never_reopens(): void
    {
        // Reabrir en lote borraría fecha, autor y nombre de incidencias
        // atendidas hace semanas: eso se pide de una en una, mirándolas.
        $corrida = $this->storedRun();
        $atendida = $this->incident($corrida, '1');
        $atendida->handled_at = now()->subWeek();
        $atendida->handled_by_name = 'Quien la atendió';
        $atendida->save();

        // Releída de la base: comparar contra la Carbon en memoria tropieza con
        // los microsegundos que Postgres redondea al guardar.
        $cuando = $atendida->refresh()->handled_at;

        Livewire::test('incident-run', ['date' => '2026-08-03'])
            ->set('selection', [$atendida->id])
            ->call('manageSelection')
            ->set('note', 'Repaso del lote')
            ->call('saveSelection')
            ->assertHasNoErrors();

        $atendida->refresh();

        $this->assertTrue($atendida->handled_at->equalTo($cuando));
        $this->assertSame('Quien la atendió', $atendida->handled_by_name);
        $this->assertSame('Repaso del lote', $atendida->handling_note);
    }

    public function test_a_batch_comment_does_not_leave_open_incidents_commented(): void
    {
        // La misma regla que en el diálogo de una sola. Aquí sólo estorba si
        // alguna de las marcadas se quedaría abierta.
        $corrida = $this->storedRun();
        $abierta = $this->incident($corrida, '1');

        Livewire::test('incident-run', ['date' => '2026-08-03'])
            ->call('manageSelection', [(string) $abierta->id])
            ->set('note', 'Mismo lote.')
            ->call('saveSelection')
            ->assertHasErrors('handled');

        $this->assertNull($abierta->refresh()->handling_note);
    }

    public function test_a_batch_comment_on_incidents_already_handled_is_fine(): void
    {
        // Un repaso sobre lo ya cerrado: no deja ninguna abierta y comentada.
        $corrida = $this->storedRun();
        $atendida = $this->incident($corrida, '1');
        $atendida->forceFill([
            'handled_at' => now()->subWeek(),
            'handled_by_name' => 'Quien la atendió',
        ])->save();

        Livewire::test('incident-run', ['date' => '2026-08-03'])
            ->call('manageSelection', [(string) $atendida->id])
            ->set('note', 'Repaso del lote')
            ->call('saveSelection')
            ->assertHasNoErrors();

        $this->assertSame('Repaso del lote', $atendida->refresh()->handling_note);
    }

    public function test_the_selection_is_looked_up_and_not_believed(): void
    {
        // Los ids llegan del navegador: ni un paquete correcto se «atiende», ni
        // se toca el de otra jornada por pasar su número.
        $corrida = $this->storedRun();
        $incidencia = $this->incident($corrida, '1');
        $correcto = $this->package($corrida, '2');
        $otraJornada = $this->incident($this->storedRun('2026-08-04'), '3');

        Livewire::test('incident-run', ['date' => '2026-08-03'])
            ->set('selection', [$incidencia->id, $correcto->id, $otraJornada->id])
            ->call('manageSelection')
            ->set('handled', true)
            ->call('saveSelection')
            ->assertHasNoErrors();

        $this->assertNotNull($incidencia->refresh()->handled_at);
        $this->assertNull($correcto->refresh()->handled_at);
        $this->assertNull($otraJornada->refresh()->handled_at);
    }

    public function test_the_section_checkbox_carries_the_ids_of_its_rows(): void
    {
        // Marcar y desmarcar es cosa del navegador desde el 18/08/2026: con una
        // ida al servidor por casilla, cada clic repintaba la jornada entera.
        // Lo que se puede fijar desde aquí es que la casilla sale cableada.
        $corrida = $this->storedRun();
        $unas = collect(['1', '2'])->map(fn ($id) => $this->incident($corrida, $id))->pluck('id')->all();

        $html = Livewire::test('incident-run', ['date' => '2026-08-03'])->html();

        $this->assertStringContainsString('x-model="marcadas"', $html);

        foreach ($unas as $id) {
            $this->assertStringContainsString('value="'.$id.'"', $html);
        }

        // Y la de la cabecera lleva los ids de su lista para marcarlas de una vez.
        $this->assertStringContainsString('.every(id => marcadas.includes(id))', $html);
    }

    public function test_an_incident_already_handled_has_no_checkbox(): void
    {
        // Gestionar en lote es cerrar: una ya cerrada no tiene nada que hacer
        // ahí, y ofrecer su casilla invita a marcarla para nada.
        $corrida = $this->storedRun();
        $pendiente = $this->incident($corrida, '1');
        $atendida = $this->incident($corrida, '2');
        $atendida->forceFill([
            'handled_at' => now(),
            'handled_by' => auth()->id(),
            'handled_by_name' => 'Quien mira',
        ])->save();

        $html = Livewire::test('incident-run', ['date' => '2026-08-03'])->html();

        $this->assertStringContainsString('value="'.$pendiente->id.'"', $html);
        $this->assertStringNotContainsString('value="'.$atendida->id.'"', $html);

        // Y la de la cabecera tampoco la ofrece: marca las de su lista, y ésa
        // ya no está en ella.
        $this->assertStringNotContainsString('"'.$atendida->id.'"]', $html);
    }

    public function test_with_every_incident_handled_the_section_checkbox_is_gone(): void
    {
        $corrida = $this->storedRun();
        $this->incident($corrida, '1')->forceFill([
            'handled_at' => now(),
            'handled_by' => auth()->id(),
            'handled_by_name' => 'Quien mira',
        ])->save();

        $html = Livewire::test('incident-run', ['date' => '2026-08-03'])->html();

        $this->assertStringNotContainsString('Marcar todas las de esta lista', $html);
    }

    public function test_the_selection_travels_only_when_the_bulk_dialog_opens(): void
    {
        $corrida = $this->storedRun();
        $lote = collect(['1', '2'])->map(fn ($id) => $this->incident($corrida, $id))->pluck('id')->all();

        // Los ids llegan del navegador en la propia llamada, no de un
        // `wire:model` que va y viene con cada casilla.
        Livewire::test('incident-run', ['date' => '2026-08-03'])
            ->call('manageSelection', array_map('strval', $lote))
            ->assertSet('bulk', true)
            ->assertSet('selection', $lote);
    }

    public function test_pressing_manage_without_anything_marked_says_so(): void
    {
        $this->storedRun();

        Livewire::test('incident-run', ['date' => '2026-08-03'])
            ->call('manageSelection')
            ->assertSet('bulk', false)
            ->assertDispatched('toast', type: 'warning');
    }

    public function test_reading_a_day_does_not_let_you_manage_in_bulk_either(): void
    {
        $corrida = $this->storedRun();
        $incidencia = $this->incident($corrida, '1');

        $mirona = User::factory()->withoutRole()->create();
        $mirona->givePermissionTo('incidents.view');
        $this->actingAs($mirona);

        // Ni las casillas se pintan, ni sirve llamar a los métodos a mano.
        $this->get($this->url())->assertOk()->assertDontSee('Marcar todas las de esta lista');

        Livewire::test('incident-run', ['date' => '2026-08-03'])
            ->set('selection', [$incidencia->id])
            ->call('manageSelection')
            ->assertForbidden();

        Livewire::test('incident-run', ['date' => '2026-08-03'])
            ->set('selection', [$incidencia->id])
            ->set('handled', true)
            ->call('saveSelection')
            ->assertForbidden();

        $this->assertNull($incidencia->refresh()->handled_at);
    }

    public function test_the_bar_and_the_checkboxes_are_there_for_who_can_manage(): void
    {
        $corrida = $this->storedRun();
        $incidencia = $this->incident($corrida, '1');

        $this->get($this->url())->assertOk()->assertSee('Marcar todas las de esta lista');

        // La barra sale sin recuento: lo dice el diálogo, justo antes de
        // escribir en todas.
        Livewire::test('incident-run', ['date' => '2026-08-03'])
            ->set('selection', [$incidencia->id])
            ->assertSee('Gestionar juntas')
            ->assertDontSee('1 incidencia marcada');
    }

    // --- Lo que cuesta abrir un diálogo (18/08/2026) -------------------------

    /** Los trozos de isla que la respuesta manda al navegador. */
    private function islas($componente): string
    {
        return implode('', $componente->effects['islandFragments'] ?? []);
    }

    public function test_opening_a_dialog_does_not_repaint_the_listing(): void
    {
        // El listado es una isla justo por esto: con ~650 paquetes, abrir un
        // diálogo volvía a pintar la jornada entera —640 ms y 2 MB de HTML— para
        // cambiar un modal.
        $corrida = $this->storedRun();
        $incidencia = $this->incident($corrida, '1');

        $componente = Livewire::test('incident-run', ['date' => '2026-08-03'])
            ->call('manage', $incidencia->id);

        $this->assertSame('', $this->islas($componente));
        $this->assertStringNotContainsString('Se fueron con otra ruta', $componente->html());

        // Y el diálogo sí está: lo que no se repinta es lo de debajo.
        $componente->assertSee('Gestión de la incidencia');
    }

    public function test_the_listing_is_not_even_queried_to_open_a_dialog(): void
    {
        $corrida = $this->storedRun();
        $incidencia = $this->incident($corrida, '1');

        $componente = Livewire::test('incident-run', ['date' => '2026-08-03']);

        $consultas = [];
        \DB::listen(function ($query) use (&$consultas) {
            $consultas[] = $query->sql;
        });

        $componente->call('manage', $incidencia->id);

        // Ninguna consulta trae la lista de paquetes: las que quedan son los
        // agregados del balance y el paquete del propio diálogo.
        foreach ($consultas as $sql) {
            $this->assertStringNotContainsString('order by "belt_time"', $sql);
        }

        $this->assertLessThan(6, count($consultas), 'Abrir el diálogo disparó '.count($consultas).' consultas.');
    }

    public function test_the_row_buttons_call_through_wire_and_not_with_wire_click(): void
    {
        // La trampa de las islas: un `wire:click` **dentro** de una isla le dice
        // a Livewire que repinte sólo esa isla, y estos dos diálogos viven
        // fuera. Con `wire:click` el icono no abría nada; por `$wire` la
        // petición no lleva isla y se repinta todo menos ellas.
        $corrida = $this->storedRun();
        $incidencia = $this->incident($corrida, '1');

        $html = Livewire::test('incident-run', ['date' => '2026-08-03'])->html();

        $this->assertStringContainsString('x-on:click="$wire.manage('.$incidencia->id.')"', $html);
        $this->assertStringContainsString('x-on:click="$wire.show('.$incidencia->id.')"', $html);
        $this->assertStringNotContainsString('wire:click="manage(', $html);
        $this->assertStringNotContainsString('wire:click="show(', $html);
    }

    public function test_saving_repaints_the_listing(): void
    {
        // Una isla no se repinta sola: si alguien quita el `refreshListing()`,
        // la fila se quedaría diciendo «Pendiente» con la incidencia atendida.
        $corrida = $this->storedRun();
        $incidencia = $this->incident($corrida, '1');

        $componente = Livewire::test('incident-run', ['date' => '2026-08-03'])
            ->call('manage', $incidencia->id)
            ->set('handled', true)
            ->call('saveHandling');

        $this->assertStringContainsString('Atendida', $this->islas($componente));
    }

    public function test_saving_a_batch_repaints_the_listing_and_clears_the_selection(): void
    {
        $corrida = $this->storedRun();
        $lote = collect(['1', '2'])->map(fn ($id) => $this->incident($corrida, $id))->pluck('id')->all();

        $componente = Livewire::test('incident-run', ['date' => '2026-08-03'])
            ->call('manageSelection', array_map('strval', $lote))
            ->set('handled', true)
            ->call('saveSelection');

        $this->assertStringContainsString('Atendida', $this->islas($componente));

        // Y el navegador vacía su selección al recibir esto.
        $componente->assertDispatched('seleccion-limpia');
    }

    /**
     * Fase 13.C, regla 1: **el importe nunca va solo**. Sin decir sobre cuántos envíos se
     * sumó, una ruta a la que le faltan valoraciones de Envexpress parece menos rentable de
     * lo que fue, y nadie ve que el dato falta.
     */
    public function test_route_revenue_always_says_over_how_many_shipments(): void
    {
        $corrida = $this->storedRun();
        $this->package($corrida, '1', assignedRoute: 'Ruta 3', revenue: 400.00);
        $this->package($corrida, '2', assignedRoute: 'Ruta 3', revenue: 205.95);
        // El tercero no está en Envexpress: suma cero euros pero sí cuenta como envío.
        $this->package($corrida, '3', assignedRoute: 'Ruta 3', revenue: null);

        $html = Livewire::test('incident-run', ['date' => '2026-08-03'])->html();

        $this->assertStringContainsString('605,95 €', $html);
        $this->assertStringContainsString('(2 de 3 envíos)', $html);
    }

    /**
     * Un nulo es «no se sabe», no cero. Una ruta sin ninguna valoración no puede rotularse
     * «0,00 €»: eso afirmaría que no dejó nada.
     */
    public function test_a_route_without_any_revenue_does_not_show_zero(): void
    {
        $corrida = $this->storedRun();
        $this->package($corrida, '1', assignedRoute: 'Ruta 3', revenue: null);

        $html = Livewire::test('incident-run', ['date' => '2026-08-03'])->html();

        $this->assertStringContainsString('sin dato de ganancia', $html);
        $this->assertStringNotContainsString('0,00 €', $html);
    }

    /**
     * Fase 13.C, regla 2: esta suma sólo tiene los envíos **con ruta**. El 07/08/2026 eran
     * 2.615,16 € de los 4.871,10 € del día, así que llamarla «del día» diría la mitad.
     */
    public function test_the_day_total_is_labelled_as_routes_not_as_the_day(): void
    {
        $corrida = $this->storedRun();
        $this->package($corrida, '1', assignedRoute: 'Ruta 3', revenue: 400.00);
        $this->package($corrida, '2', assignedRoute: 'Ruta 4', revenue: 205.95);

        $respuesta = $this->get($this->url())->assertOk();

        $respuesta->assertSee('Ganancia de las rutas:');
        $respuesta->assertSee('605,95 €');
        $respuesta->assertSee('sobre 2 de 2 envíos');
        $respuesta->assertDontSee('Ganancia del día');
    }

    /** Una jornada anterior a la v4 no trae el campo: la pantalla calla, no inventa un cero. */
    public function test_a_pre_v4_run_shows_no_revenue_at_all(): void
    {
        $corrida = $this->storedRun();
        $this->incident($corrida, '1');

        $respuesta = $this->get($this->url())->assertOk();

        $respuesta->assertDontSee('Ganancia de las rutas:');
        $respuesta->assertDontSee('€');
    }

    /**
     * El código de barras en la propia fila: es con lo que se busca el paquete en el portal,
     * y tenerlo que sacar abriendo el diálogo de una en una hace impracticable contrastar
     * una jornada contra el listado en texto del bot.
     */
    public function test_each_incident_row_carries_its_barcode(): void
    {
        $corrida = $this->storedRun();
        $incidencia = $this->incident($corrida, '1334043165');

        $this->get($this->url())->assertOk()->assertSee($incidencia->barcode);
    }

    /** Lo que dejó **ese** envío, en su fila. */
    public function test_each_incident_row_carries_its_revenue(): void
    {
        $corrida = $this->storedRun();
        $this->incident($corrida, '1', revenue: 8.60);

        $this->get($this->url())->assertOk()->assertSee('8,60 €');
    }

    /**
     * Un envío que no está en Envexpress se pinta «—», no «0,00 €». En una fila suelta el
     * cero es todavía más engañoso que en un total: parece un envío que no se cobró.
     */
    public function test_an_incident_without_revenue_shows_a_dash_not_a_zero(): void
    {
        $corrida = $this->storedRun();
        $this->incident($corrida, '1', revenue: null);

        $this->get($this->url())->assertOk()->assertDontSee('0,00 €');
    }
}
