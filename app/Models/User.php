<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
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

#[Fillable(['name', 'last_name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    // El hash de la contraseña y el remember_token quedan fuera del
    // historial por el #[Hidden] de arriba: ver Auditable::auditExcludedFields().
    use Auditable, HasFactory, Notifiable, SoftDeletes;

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
