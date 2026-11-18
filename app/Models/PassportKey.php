<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use phpseclib4\Crypt\RSA;

/**
 * Par de chaves que assina os tokens do OAuth. A chave privada fica criptografada pela APP_KEY; a mais recente é a
 * chave em uso. Trocar a chave (rotate) invalida todos os tokens emitidos: as ferramentas de IA pedem nova autorização.
 *
 * @property string $private_key
 * @property string $public_key
 */
#[Fillable(['private_key', 'public_key'])]
#[Hidden(['private_key'])]
class PassportKey extends Model
{
    /** Tamanho da chave RSA, o mesmo padrão do comando passport:keys. */
    public const LENGTH = 4096;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['private_key' => 'encrypted'];
    }

    /**
     * Chave em uso; na primeira vez, gera e grava. O lock evita que dois pods gerem chaves diferentes ao mesmo tempo.
     */
    public static function current(): self
    {
        $key = static::query()->latest('id')->first();
        if ($key) {
            return $key;
        }

        return Cache::lock('passport-keys:generate', 60)->block(60, fn (): self => static::query()->latest('id')->first() ?? static::generate());
    }

    /**
     * Gera um novo par de chaves e passa a usá-lo.
     */
    public static function generate(): self
    {
        $key = RSA::createKey(self::LENGTH);

        return static::create(['private_key' => (string) $key, 'public_key' => (string) $key->getPublicKey()]);
    }
}
