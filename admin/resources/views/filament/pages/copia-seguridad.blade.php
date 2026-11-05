<x-filament-panels::page>
    <x-filament::section heading="Qué incluye">
        <ul class="list-disc pl-5 space-y-1">
            <li>Toda la base de datos: hogares, perfiles, despensa, menús, lo comido y los usuarios del panel (con su contraseña cifrada).</li>
            <li><strong>No</strong> incluye la clave de conexión con Claude (se cifra con la clave de la aplicación): tras restaurar en un servicio nuevo, vuelve a pegarla en «Conexión con Claude».</li>
            <li><strong>No</strong> incluye las sesiones, la cola de trabajos ni la memoria interna del agente.</li>
        </ul>
    </x-filament::section>
    <x-filament::section heading="Cómo usarla">
        <ol class="list-decimal pl-5 space-y-1">
            <li>Pulsa «Descargar copia» y guarda el archivo <code>.sql.gz</code> fuera del servicio: es un volcado SQL de los datos (TRUNCATE + INSERT por tabla).</li>
            <li>La aplicación no restaura copias: se hace con psql sobre una base que ya tenga el esquema (migraciones aplicadas).</li>
            <li>Restaurar: <code>gunzip -c nutrition.sql.gz | psql "$DATABASE_URL" -v ON_ERROR_STOP=1</code>. Sustituye todos los datos actuales.</li>
        </ol>
    </x-filament::section>
</x-filament-panels::page>
