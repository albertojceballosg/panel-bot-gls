<?php

namespace App\Support;

/**
 * Qué se puede hacer en cada pantalla, y qué puede cada rol (CONTEXTO.md §7,
 * fase 12).
 *
 * El catálogo es la única fuente, como `SettingsCatalog`: de aquí salen los
 * permisos que siembra el seeder, lo que se enseña en el maestro de usuarios y
 * lo que comprueban las rutas y las pantallas. Añadir un módulo es una línea
 * aquí y una llamada al seeder, no una migración.
 *
 * **Dos acciones por módulo y no un CRUD entero** (decisión del 18/08/2026):
 * hoy ninguna pantalla separa crear de editar, así que `create` y `update`
 * serían dos permisos que nadie pondría distintos. Lo único que de verdad se
 * distingue es mirar frente a escribir.
 *
 * Las claves van en inglés como el resto del código; las etiquetas, en
 * castellano, porque se leen en pantalla.
 */
class PermissionCatalog
{
    /** Entrar en la pantalla y leerla. */
    public const VIEW = 'view';

    /** Escribir: crear, editar, dar de baja, restaurar, configurar. */
    public const MANAGE = 'manage';

    /** Puede con todo, incluidas las cuentas y las copias de la base. */
    public const ROLE_ADMIN = 'Administrador';

    /**
     * El trabajo del día: el maestro del cliente y las incidencias. No toca
     * usuarios ni copias —el permiso más fuerte del panel (§10)— y de las
     * configuraciones sólo mira, que es lo que hace falta para entender por qué
     * una pantalla pinta lo que pinta.
     */
    public const ROLE_OPERATIONS = 'Operaciones';

    /**
     * Los módulos con permiso, en el orden en que se leen en la barra lateral.
     *
     * `route` es la pantalla a la que da entrada: la usa el menú para esconder
     * lo que no se puede ver.
     *
     * @return array<string, array{label: string, route: string, actions: array<string, string>}>
     */
    public static function modules(): array
    {
        return [
            'pickup-routes' => [
                'label' => 'Rutas',
                'route' => 'pickup-routes',
                'actions' => [
                    self::VIEW => 'Ver las rutas de recogida',
                    self::MANAGE => 'Crear, editar y dar de baja rutas',
                ],
            ],

            'couriers' => [
                'label' => 'UT',
                'route' => 'couriers',
                'actions' => [
                    self::VIEW => 'Ver las UT y su capacidad',
                    self::MANAGE => 'Crear, editar y dar de baja UT',
                ],
            ],

            'merchants' => [
                'label' => 'Comercios',
                'route' => 'merchants',
                'actions' => [
                    self::VIEW => 'Ver los comercios y su ruta',
                    self::MANAGE => 'Crear, editar y dar de baja comercios',
                ],
            ],

            // Sólo `view`: el historial no se edita ni se borra nunca (§4), así
            // que un `manage` aquí sería un permiso sin nada detrás.
            'audit-logs' => [
                'label' => 'Auditoría',
                'route' => 'audit-logs',
                'actions' => [
                    self::VIEW => 'Ver el historial de cambios del maestro',
                ],
            ],

            'incidents' => [
                'label' => 'Incidencias',
                'route' => 'incidents',
                'actions' => [
                    self::VIEW => 'Ver las jornadas y su detalle',
                    self::MANAGE => 'Comentar una incidencia y darla por atendida',
                ],
            ],

            // Las jornadas las escribe el bot (§3.1) y este calendario es una
            // lectura de ellas: no hay nada que gestionar.
            'capacity-calendar' => [
                'label' => 'Calendario de capacidades',
                'route' => 'capacity-calendar',
                'actions' => [
                    self::VIEW => 'Ver el calendario de capacidades',
                ],
            ],

            // Vive dentro de Configuraciones en el menú, pero tiene permiso propio: es un
            // maestro con sus altas y sus bajas, no un parámetro de otra pantalla, y quien
            // ajusta un color no tiene por qué poder tocar lo que cuesta la agencia.
            //
            // **Un solo módulo para las dos pantallas**, el catálogo de conceptos y los
            // gastos por ruta: son las dos mitades de lo mismo y separarlas daría un permiso
            // de tocar los nombres sin poder tocar los importes, que no protege de nada.
            'expenses' => [
                'label' => 'Gastos',
                'route' => 'route-expenses',
                'actions' => [
                    self::VIEW => 'Ver los gastos de cada ruta y sus conceptos',
                    self::MANAGE => 'Crear, editar y retirar gastos y conceptos',
                ],
            ],

            'settings' => [
                'label' => 'Configuraciones',
                'route' => 'settings',
                'actions' => [
                    self::VIEW => 'Ver los parámetros de cada módulo',
                    self::MANAGE => 'Cambiar los parámetros de cada módulo',
                ],
            ],

            // El maestro de los propios roles. Quien puede tocarlo puede
            // dárselo todo a sí mismo, así que va con las cuentas y las copias:
            // sólo el Administrador.
            'roles' => [
                'label' => 'Roles y permisos',
                'route' => 'roles',
                'actions' => [
                    self::VIEW => 'Ver los roles y qué lleva cada uno',
                    self::MANAGE => 'Crear roles, cambiarles los permisos y borrarlos',
                ],
            ],

            'users' => [
                'label' => 'Usuarios',
                'route' => 'users',
                'actions' => [
                    self::VIEW => 'Ver las cuentas del panel',
                    self::MANAGE => 'Crear cuentas, cambiarles el rol y darlas de baja',
                ],
            ],

            // Sin `view`: entrar en la pantalla ya es poder descargarse la base
            // entera del cliente. Partirlo en dos daría un permiso de mirar que
            // no protege de nada (§10).
            'backups' => [
                'label' => 'Copias de seguridad',
                'route' => 'backups',
                'actions' => [
                    self::MANAGE => 'Descargar y restaurar copias de la base',
                ],
            ],
        ];
    }

    /**
     * Todos los permisos, con la etiqueta con la que se leen.
     *
     * @return array<string, string> `módulo.acción` => qué permite
     */
    public static function all(): array
    {
        $permisos = [];

        foreach (self::modules() as $clave => $modulo) {
            foreach ($modulo['actions'] as $accion => $etiqueta) {
                $permisos[self::name($clave, $accion)] = $etiqueta;
            }
        }

        return $permisos;
    }

    public static function name(string $module, string $action): string
    {
        return $module.'.'.$action;
    }

    /**
     * Cómo se llama un módulo de cara al usuario. Los que no están en el
     * catálogo son de permisos creados a mano: se enseña su clave tal cual, que
     * es más honesto que inventarles un nombre.
     */
    public static function moduleLabel(string $module): string
    {
        return self::modules()[$module]['label'] ?? $module;
    }

    /**
     * Los permisos del catálogo agrupados por módulo, con sus etiquetas: es lo
     * que pinta el formulario de un rol.
     *
     * @return array<string, array{label: string, permissions: array<string, string>}>
     */
    public static function grouped(): array
    {
        $grupos = [];

        foreach (self::modules() as $clave => $modulo) {
            $permisos = [];

            foreach ($modulo['actions'] as $accion => $etiqueta) {
                $permisos[self::name($clave, $accion)] = $etiqueta;
            }

            $grupos[$clave] = ['label' => $modulo['label'], 'permissions' => $permisos];
        }

        return $grupos;
    }

    /**
     * Qué lleva cada rol.
     *
     * El administrador se define como «todo lo del catálogo» y no como una
     * lista: si no, cada permiso nuevo nacería sin que nadie pudiera usarlo
     * hasta acordarse de añadirlo aquí.
     *
     * @return array<string, list<string>>
     */
    public static function roles(): array
    {
        return [
            self::ROLE_ADMIN => array_keys(self::all()),

            self::ROLE_OPERATIONS => [
                self::name('pickup-routes', self::VIEW),
                self::name('pickup-routes', self::MANAGE),
                self::name('couriers', self::VIEW),
                self::name('couriers', self::MANAGE),
                self::name('merchants', self::VIEW),
                self::name('merchants', self::MANAGE),
                self::name('audit-logs', self::VIEW),
                self::name('incidents', self::VIEW),
                self::name('incidents', self::MANAGE),
                self::name('capacity-calendar', self::VIEW),

                // Mira los parámetros pero no los toca: cambiarlos mueve
                // acusaciones contra personas (§7, fase 11) y colores de una
                // pantalla que lee todo el equipo.
                self::name('settings', self::VIEW),

                // Los gastos, igual: se leen para entender un cálculo, pero lo que cuesta la
                // agencia no lo cambia quien reparte paquetes.
                self::name('expenses', self::VIEW),
            ],
        ];
    }

    /** @return list<string> Los nombres de los roles, para validar y para el desplegable. */
    public static function roleNames(): array
    {
        return array_keys(self::roles());
    }
}
