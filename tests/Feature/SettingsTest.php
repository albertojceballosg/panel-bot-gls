<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use App\Support\AuditPresenter;
use App\Support\SettingsCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

/** Configuraciones por módulo (CONTEXTO.md §7, fase 11). */
class SettingsTest extends TestCase
{
    use RefreshDatabase;

    private const MODULO = 'capacity-calendar';

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
    }

    private function url(string $module = self::MODULO): string
    {
        return '/settings/'.$module;
    }

    private function pantalla(): Testable
    {
        return Livewire::test('settings', ['module' => self::MODULO]);
    }

    /**
     * La pantalla con el formulario entero relleno y válido.
     *
     * Hace falta desde que no hay valores por defecto: guardar exige los cinco
     * parámetros, así que un test que sólo toca uno no llegaría a guardar nada.
     */
    private function rellena(array $overrides = []): Testable
    {
        $valores = array_merge([
            'minimum_percent' => '60',
            'optimal_percent' => '85',
            'bad_color' => '#dc2626',
            'warning_color' => '#d97706',
            'good_color' => '#16a34a',
        ], $overrides);

        $pantalla = $this->pantalla();

        foreach ($valores as $clave => $valor) {
            $pantalla->set('values.'.$clave, $valor);
        }

        return $pantalla;
    }

    public function test_it_is_behind_the_session(): void
    {
        auth()->logout();

        $this->get($this->url())->assertRedirect('/login');
    }

    public function test_an_unknown_module_is_a_404(): void
    {
        // Los módulos válidos los decide el catálogo, no la URL.
        $this->get($this->url('lo-que-sea'))->assertNotFound();
    }

    public function test_the_sidebar_links_to_it(): void
    {
        $this->get('/')->assertOk()
            ->assertSee('Configuraciones')
            ->assertSee(route('settings', ['module' => self::MODULO]), escape: false);
    }

    // --- Valores por defecto ---------------------------------------------------

    public function test_without_anything_saved_the_form_opens_empty(): void
    {
        // Nada de valores por defecto: un parámetro sin configurar es un hueco,
        // no un número que el cliente nunca eligió.
        $valores = $this->pantalla()->get('values');

        $this->assertSame(SettingsCatalog::keys(self::MODULO), array_keys($valores));
        $this->assertSame([''], array_values(array_unique($valores)));
    }

    public function test_it_lists_what_is_missing_by_its_visible_name(): void
    {
        Setting::create(['module' => self::MODULO, 'key' => 'minimum_percent', 'value' => '55']);

        // Etiquetas y no claves: quien lee el aviso no sabe qué es
        // `optimal_percent`.
        $this->assertSame([
            'Porcentaje óptimo',
            'Color de un día malo',
            'Color de un día justo',
            'Color de un día bueno',
        ], Setting::missing(self::MODULO));

        $this->assertFalse(Setting::configured(self::MODULO));
    }

    public function test_what_is_saved_comes_back(): void
    {
        Setting::create(['module' => self::MODULO, 'key' => 'minimum_percent', 'value' => '55']);

        $valores = $this->pantalla()->get('values');

        $this->assertSame('55', $valores['minimum_percent']);
        $this->assertSame('', $valores['optimal_percent']);
    }

    public function test_a_module_with_everything_saved_is_configured(): void
    {
        $this->rellena()->call('save')->assertHasNoErrors();

        $this->assertSame([], Setting::missing(self::MODULO));
        $this->assertTrue(Setting::configured(self::MODULO));
    }

    // --- Guardar ---------------------------------------------------------------

    public function test_it_saves_the_thresholds_and_the_colours(): void
    {
        $this->rellena([
            'minimum_percent' => '70',
            'optimal_percent' => '90',
            'good_color' => '#00ff88',
        ])->call('save')
            ->assertHasNoErrors()
            ->assertDispatched('toast', message: 'Configuración guardada.');

        $guardado = Setting::for(self::MODULO);

        $this->assertSame('70', $guardado['minimum_percent']);
        $this->assertSame('90', $guardado['optimal_percent']);
        $this->assertSame('#00ff88', $guardado['good_color']);
    }

    public function test_saving_twice_does_not_duplicate_a_parameter(): void
    {
        $this->rellena(['minimum_percent' => '70'])->call('save');
        $this->rellena(['minimum_percent' => '75'])->call('save');

        $this->assertSame(1, Setting::where('key', 'minimum_percent')->count());
        $this->assertSame('75', Setting::for(self::MODULO)['minimum_percent']);
    }

    public function test_it_only_writes_what_the_catalogue_declares(): void
    {
        // Lo que llegue de más se descarta en vez de acabar en la base.
        Setting::store(self::MODULO, ['minimum_percent' => '70', 'lo_que_sea' => 'x']);

        $this->assertSame(0, Setting::where('key', 'lo_que_sea')->count());
    }

    // --- Validación -------------------------------------------------------------

    public function test_a_percentage_out_of_range_is_rejected(): void
    {
        $this->rellena(['minimum_percent' => '0'])->call('save')
            ->assertHasErrors(['values.minimum_percent']);

        $this->rellena(['minimum_percent' => '101'])->call('save')
            ->assertHasErrors(['values.minimum_percent']);

        $this->assertSame(0, Setting::count());
    }

    public function test_the_optimal_has_to_be_above_the_minimum(): void
    {
        // Al revés, los tres tramos no existen: no habría «justo».
        $this->rellena(['minimum_percent' => '90', 'optimal_percent' => '80'])
            ->call('save')
            ->assertHasErrors(['values.optimal_percent']);

        $this->assertSame(0, Setting::count());
    }

    public function test_a_colour_that_is_not_a_hex_is_rejected(): void
    {
        // El `<input type="color">` siempre manda `#rrggbb`, pero el campo de
        // texto de al lado deja escribir cualquier cosa, y esto acaba en un
        // atributo `style`.
        $this->rellena(['good_color' => 'javascript:alert(1)'])
            ->call('save')
            ->assertHasErrors(['values.good_color']);

        $this->assertSame(0, Setting::count());
    }

    public function test_the_messages_name_the_field_in_spanish(): void
    {
        $errores = $this->pantalla()->call('save')->errors();

        $this->assertSame('El campo porcentaje mínimo es obligatorio.', $errores->first('values.minimum_percent'));
    }

    // --- Historial ---------------------------------------------------------------

    public function test_a_change_is_recorded_with_a_readable_name(): void
    {
        $this->rellena(['minimum_percent' => '70'])->call('save');

        $entrada = Setting::where('key', 'minimum_percent')->sole()->auditLogs()->sole();

        // Un umbral cambia cómo se lee una pantalla entera: quién lo movió y
        // cuándo es justo para lo que existe el historial (§4).
        $this->assertSame('70', $entrada->after['value']);
        $this->assertSame(
            'Calendario de capacidades · Porcentaje mínimo',
            AuditPresenter::make()->record($entrada),
        );
    }

    // --- Pantalla -----------------------------------------------------------------

    public function test_the_form_has_a_colour_picker_per_colour(): void
    {
        $this->get($this->url())->assertOk()->assertSee('type="color"', escape: false);
    }
}
