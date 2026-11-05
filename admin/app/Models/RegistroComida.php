<?php

namespace App\Models;

use App\Enums\MomentoComida;
use App\Enums\OrigenComida;
use App\Models\Concerns\AceptaClavesDeFormulario;
use Database\Factories\RegistroComidaFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Lo que un perfil comió de verdad. Es el historial del agente: un menú planificado no cuenta como comido. */
#[Fillable(['fecha', 'momento', 'origen', 'descripcion', 'categorias', 'opcion_ref', 'foto_hash'])]
class RegistroComida extends Model
{
    /** @use HasFactory<RegistroComidaFactory> */
    use AceptaClavesDeFormulario, HasFactory, HasUuids;

    /** @var list<string> */
    protected array $clavesDeFormulario = ['perfil_id'];

    protected $table = 'registros_comida';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['fecha' => 'date', 'momento' => MomentoComida::class, 'origen' => OrigenComida::class, 'categorias' => 'array'];
    }

    /** @return BelongsTo<Perfil, $this> */
    public function perfil(): BelongsTo
    {
        return $this->belongsTo(Perfil::class);
    }

    /** La foto de lo comido (archivo del chat), si la hay. Sin clave foránea en la base: puede que el archivo ya no exista. @return BelongsTo<ChatArchivo, $this> */
    public function foto(): BelongsTo
    {
        return $this->belongsTo(ChatArchivo::class, 'foto_hash', 'hash');
    }

    /** @return BelongsTo<Menu, $this> */
    public function menu(): BelongsTo
    {
        return $this->belongsTo(Menu::class);
    }
}
