<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Multimedia polimórfica (docs/events-plan.md): imágenes por URL (Cloudinary) y
 * videos de YouTube. De YouTube se guarda la URL normalizada y el id; el embed lo
 * arma el frontend desde el id. Nunca se guarda HTML.
 */
class Media extends Model
{
    public const TYPE_IMAGE = 'image';

    public const TYPE_VIDEO = 'video';

    public const PROVIDER_CLOUDINARY = 'cloudinary';

    public const PROVIDER_YOUTUBE = 'youtube';

    /** Imagen por URL que no es de Cloudinary (pegada a mano). */
    public const PROVIDER_EXTERNAL = 'external';

    protected $table = 'media';

    protected $fillable = [
        'type',
        'provider',
        'url',
        'provider_id',
        'thumbnail_url',
        'alt',
        'order',
    ];

    protected $casts = [
        'order' => 'integer',
    ];

    /** @return MorphTo<Model, $this> */
    public function mediable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Id de un video de YouTube a partir de cualquiera de sus formas de URL
     * (watch, youtu.be, embed, shorts, live, nocookie). `null` si no es YouTube.
     */
    public static function youTubeId(string $url): ?string
    {
        $parts = parse_url(trim($url));
        if (! is_array($parts) || ! isset($parts['host'])) {
            return null;
        }

        $host = strtolower(preg_replace('/^(www\.|m\.)/', '', $parts['host']) ?? '');
        $path = $parts['path'] ?? '';
        $candidate = null;

        if ($host === 'youtu.be') {
            $candidate = ltrim($path, '/');
        } elseif (in_array($host, ['youtube.com', 'youtube-nocookie.com', 'music.youtube.com'], true)) {
            if ($path === '/watch') {
                parse_str($parts['query'] ?? '', $query);
                $candidate = is_string($query['v'] ?? null) ? $query['v'] : null;
            } elseif (preg_match('#^/(embed|shorts|live|v)/([^/?]+)#', $path, $m)) {
                $candidate = $m[2];
            }
        }

        return $candidate !== null && preg_match('/^[A-Za-z0-9_-]{11}$/', $candidate) ? $candidate : null;
    }
}
