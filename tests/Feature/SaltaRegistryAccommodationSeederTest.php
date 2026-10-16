<?php

namespace Tests\Feature;

use App\Models\Accommodation;
use Database\Seeders\SaltaRegistryAccommodationSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Importación del registro provincial de Salta: completa sin pisar, crea
 * borradores invisibles al público y se puede re-correr sin duplicar.
 */
class SaltaRegistryAccommodationSeederTest extends TestCase
{
    use DatabaseTransactions;

    private const SOURCE = 'salta-registro-2026-10-08';

    public function test_completa_los_vacios_de_un_existente_sin_pisar_lo_cargado(): void
    {
        $almeria = $this->existingWithSlug('hotel-almeria');
        $almeria->forceFill(['email' => 'propio@hotel.test', 'stars' => null, 'address' => 'Dirección propia 1'])->save();

        $this->seed(SaltaRegistryAccommodationSeeder::class);

        $almeria->refresh();
        $this->assertSame('propio@hotel.test', $almeria->email);
        $this->assertSame('Dirección propia 1', $almeria->address);
        $this->assertSame(4, $almeria->stars);

        $import = DB::table('accommodation_imports')->where('source', self::SOURCE)->where('accommodation_id', $almeria->id)->first();
        $this->assertSame('enriched', $import->action);
        $this->assertSame('reservas@hotelalmeria.com.ar', json_decode($import->conflicts, true)['email']['registro']);
    }

    public function test_lo_nuevo_entra_como_borrador_no_publico(): void
    {
        $this->seed(SaltaRegistryAccommodationSeeder::class);

        $created = Accommodation::whereIn('id', DB::table('accommodation_imports')
            ->where('source', self::SOURCE)->where('action', 'created')->select('accommodation_id'));

        $this->assertGreaterThan(600, $created->count());
        $this->assertSame(0, (clone $created)->whereNotNull('account_id')->count());
        $this->assertSame(0, (clone $created)->where('enabled', true)->count());

        // Paraje al municipio, con el paraje en address2.
        $calderilla = (clone $created)->where('address2', 'La Calderilla')->first();
        $this->assertNotNull($calderilla);
        $this->assertSame('La Caldera', DB::table('cities')->where('id', $calderilla->city_id)->value('name'));

        // La categoría A/B de los residenciales es un tipo propio.
        $this->assertTrue(DB::table('accommodation_types')->where('name', 'Residencial A')->exists());
    }

    public function test_re_correr_no_duplica(): void
    {
        $this->seed(SaltaRegistryAccommodationSeeder::class);
        $accommodations = Accommodation::count();
        $imports = DB::table('accommodation_imports')->count();
        $cities = DB::table('cities')->count();

        $this->seed(SaltaRegistryAccommodationSeeder::class);

        $this->assertSame($accommodations, Accommodation::count());
        $this->assertSame($imports, DB::table('accommodation_imports')->count());
        $this->assertSame($cities, DB::table('cities')->count());
    }

    private function existingWithSlug(string $slug): Accommodation
    {
        return Accommodation::where('slug', $slug)->orderBy('id')->first()
            ?? Accommodation::create([
                'account_id' => null,
                'name' => 'Hotel Almeria',
                'slug' => $slug,
                'city_id' => DB::table('cities')->where('name', 'Salta Capital')->value('id'),
            ]);
    }
}
