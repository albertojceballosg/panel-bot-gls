<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\AuditAction;
use App\Models\Concerns\Auditable;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Spatie\Permission\Traits\HasRoles;

#[Fillable(['name', 'last_name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    // El hash de la contraseña y el remember_token quedan fuera del
    // historial por el #[Hidden] de arriba: ver Auditable::auditExcludedFields().
    // `HasRoles` es de spatie/laravel-permission (§7, fase 12). Lo que puede
    // cada cuenta sale de su rol; el catálogo de permisos, de
    // `PermissionCatalog`.
    use Auditable, HasFactory, HasRoles, Notifiable, SoftDeletes;

    /**
     * «Alberto Ceballos», o sólo el nombre si no consta el apellido.
     *
     * Ojo, el historial no lo usa: `audit_logs` guarda el `name` que había
     * cuando se firmó, y ahí el apellido no estaba.
     */
    public function fullName(): string
    {
        return trim($this->name.' '.$this->last_name);
    }

    /**
     * Deja en el historial el cambio de rol (§4).
     *
     * El rol no es una columna de `users` sino una fila de la tabla pivote del
     * paquete, así que los eventos de Eloquent no lo ven: sin esto, quién le dio
     * el Administrador a quién no quedaría escrito en ninguna parte, y es
     * justamente el cambio que más importa poder mirar después.
     */
    public function recordRoleChange(?string $before, ?string $after): void
    {
        if ($before === $after) {
            return;
        }

        $this->writeAudit(AuditAction::Update, ['role' => $before], ['role' => $after]);
    }

    /** El rol de la cuenta, o null si todavía no tiene ninguno. */
    public function roleName(): ?string
    {
        return $this->roles->first()?->name;
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /**
     * Reglas de validación, como en el resto del maestro (§7, fase 1).
     *
     * @param  int|null  $id  Id a ignorar al comprobar unicidad (edición).
     */
    public static function rules(?int $id = null): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],

            // Obligatorio desde el 14/08/2026, aunque la columna siga siendo
            // nullable por las cuentas anteriores: la base guarda lo que hay y
            // el formulario exige lo que debería haber. La consecuencia
            // aceptada es que editar una cuenta vieja obliga a rellenarlo.
            'last_name' => ['required', 'string', 'max:255'],

            'email' => [
                'required',
                'string',
                'email',
                'max:255',
                // whereNull('deleted_at') para decir lo mismo que el índice
                // parcial de la migración: dar de baja a alguien libera su
                // correo por si vuelve, o si otra persona hereda la cuenta.
                Rule::unique('users', 'email')->ignore($id)->whereNull('deleted_at'),
            ],

            // Un rol y sólo uno. El paquete permite varios, pero con dos roles
            // y ~5 cuentas, «Administrador + Operaciones» no significa nada que
            // no signifique ya «Administrador», y una lista de casillas invita
            // a combinaciones que nadie ha pensado. Si algún día hacen falta
            // varios, el modelo de datos ya los aguanta.
            // Contra la tabla y no contra el catálogo: desde que hay pantalla
            // de roles (§7, fase 12) el cliente crea los suyos, y una lista fija
            // aquí los daría por inválidos.
            'role' => ['required', Rule::exists('roles', 'name')],

            // Obligatoria al crear y opcional al editar, donde vacío significa
            // «déjala como está»: la pantalla no puede enseñar la actual —es un
            // hash— y pedirla en cada cambio de correo invita a ponerla floja.
            'password' => [
                $id === null ? 'required' : 'nullable',
                'string',
                'confirmed',
                Password::min(8),
            ],
        ];
    }
}
