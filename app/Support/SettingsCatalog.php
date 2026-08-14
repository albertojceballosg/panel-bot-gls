<?php

namespace App\Support;

/**
 * Qué se puede configurar de cada módulo (CONTEXTO.md §7, fase 11).
 *
 * El catálogo es la única fuente: la pantalla pinta lo que hay aquí y la
 * validación sale de aquí. Añadir un parámetro es una línea en este fichero
 * —sin migración, sin tocar el Blade y sin que ninguna pantalla tenga que
 * enterarse—, y lo que se añade **nace sin configurar**, así que el módulo que
 * lo use avisará solo hasta que alguien le dé un valor.
 *
 * Los textos están en castellano porque son de cara al usuario; las claves, en
 * inglés, como el resto del código.
 */
class SettingsCatalog
{
    /**
     * Los tipos que la pantalla sabe pintar. Si haces falta uno nuevo, se añade
     * aquí y en el Blade, no en cada módulo.
     */
    public const TYPE_PERCENT = 'percent';

    public const TYPE_COLOR = 'color';

    /** @return array<string, array<string, mixed>> */
    public static function modules(): array
    {
        return [
            'capacity-calendar' => [
                'label' => 'Calendario de capacidades',
                'route' => 'capacity-calendar',
                'description' => 'Cuándo la ocupación de una furgoneta es buena, justa o mala, y de qué color se pinta.',

                'fields' => [
                    'minimum_percent' => [
                        'type' => self::TYPE_PERCENT,
                        'label' => 'Porcentaje mínimo',
                        'hint' => 'Por debajo de esto, el día se considera malo: la furgoneta salió demasiado vacía.',
                    ],
                    'optimal_percent' => [
                        'type' => self::TYPE_PERCENT,
                        'label' => 'Porcentaje óptimo',
                        'hint' => 'A partir de aquí el día se considera bueno. Tiene que ser mayor que el mínimo.',
                    ],
                    'bad_color' => [
                        'type' => self::TYPE_COLOR,
                        'label' => 'Color de un día malo',
                        'hint' => 'Por debajo del mínimo.',
                    ],
                    'warning_color' => [
                        'type' => self::TYPE_COLOR,
                        'label' => 'Color de un día justo',
                        'hint' => 'Entre el mínimo y el óptimo.',
                    ],
                    'good_color' => [
                        'type' => self::TYPE_COLOR,
                        'label' => 'Color de un día bueno',
                        'hint' => 'Del óptimo para arriba.',
                    ],
                ],

                // Lo que no se puede decir campo a campo. Va aparte porque la
                // validación genérica de abajo no sabe de la relación entre dos
                // parámetros de este módulo en concreto.
                'rules' => [
                    'optimal_percent' => ['gt:minimum_percent'],
                ],
            ],
        ];
    }

    public static function has(string $module): bool
    {
        return isset(self::modules()[$module]);
    }

    /** @return array<string, mixed> */
    public static function module(string $module): array
    {
        return self::modules()[$module] ?? [];
    }

    /**
     * Las claves que declara un módulo, en el orden en que se pintan.
     *
     * **No hay valores por defecto** (decisión del 14/08/2026): un parámetro sin
     * configurar es un hueco y se dice, no un número inventado que el cliente
     * nunca eligió y que aun así cambia cómo se lee una pantalla.
     *
     * @return list<string>
     */
    public static function keys(string $module): array
    {
        return array_keys(self::module($module)['fields'] ?? []);
    }

    /**
     * Las reglas del formulario, sacadas del tipo de cada campo.
     *
     * @param  string  $prefix  Dónde viven los valores en el componente (`values.`).
     * @return array<string, list<string>>
     */
    public static function rules(string $module, string $prefix = ''): array
    {
        $definicion = self::module($module);
        $extra = $definicion['rules'] ?? [];
        $rules = [];

        foreach ($definicion['fields'] ?? [] as $key => $field) {
            $propias = match ($field['type']) {
                // Enteros: un 82,5 % de ocupación no lo mira nadie, y con
                // decimales la comparación entre umbrales invita a errores de
                // redondeo. `min:1` porque un umbral en cero no separa nada.
                self::TYPE_PERCENT => ['required', 'integer', 'min:1', 'max:100'],

                // El `<input type="color">` siempre manda `#rrggbb`, pero el
                // campo de texto de al lado deja escribir cualquier cosa, y esto
                // acaba en un atributo `style`.
                self::TYPE_COLOR => ['required', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            };

            // Las reglas cruzadas se escriben con el nombre del campo
            // (`gt:minimum_percent`) y hay que traducirlas a dónde vive de
            // verdad, o `gt` compararía contra un campo que no existe.
            $cruzadas = array_map(
                fn (string $rule) => preg_replace_callback(
                    '/^(gt|gte|lt|lte|different|same):(\w+)$/',
                    fn (array $m) => $m[1].':'.$prefix.$m[2],
                    $rule,
                ),
                $extra[$key] ?? [],
            );

            $rules[$prefix.$key] = [...$propias, ...$cruzadas];
        }

        return $rules;
    }

    /**
     * Cómo se llama cada parámetro de cara al usuario, para la validación y
     * para el historial.
     *
     * @return array<string, string>
     */
    public static function labels(string $module, string $prefix = ''): array
    {
        return collect(self::module($module)['fields'] ?? [])
            ->mapWithKeys(fn (array $field, string $key) => [$prefix.$key => mb_strtolower($field['label'])])
            ->all();
    }
}
