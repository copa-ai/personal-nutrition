{{-- Estilos de las vistas de la ficha del menú. Filament solo compila el CSS de sus propias vistas (no hay tema propio), así que se
     definen aquí. Colores: los de Filament (--primary-*, --info-*…) con respaldo, y tintes con color-mix para que valgan en modo oscuro. --}}
@once
<style>
    .mn { --mn-linea: color-mix(in srgb, currentColor 14%, transparent); --mn-suave: color-mix(in srgb, currentColor 58%, transparent);
          --mn-tinte: color-mix(in srgb, currentColor 5%, transparent); font-size: .875rem; line-height: 1.5; }
    .mn *, .mn *::before, .mn *::after { box-sizing: border-box; }
    .mn h3, .mn h4, .mn p, .mn ul, .mn ol { margin: 0; }
    .mn-etiqueta { font-size: .6875rem; font-weight: 600; letter-spacing: .06em; text-transform: uppercase; color: var(--mn-suave); }
    .mn-suave { color: var(--mn-suave); }
    .mn-chip { display: inline-flex; align-items: center; gap: .25rem; padding: .05rem .55rem; border: 1px solid var(--mn-linea); border-radius: 999px; font-size: .75rem; color: var(--mn-suave); white-space: nowrap; }
    .mn-icono { width: 1.15rem; height: 1.15rem; flex: none; }

    /* resumen */
    .mn-resumen { display: flex; flex-direction: column; gap: 1rem; }
    .mn-ident { display: flex; flex-wrap: wrap; gap: .75rem 2.5rem; align-items: flex-end; }
    .mn-ident > div { display: flex; flex-direction: column; gap: .25rem; }
    .mn-ident .valor { font-size: 1rem; font-weight: 600; }
    .mn-kpis { display: grid; grid-template-columns: repeat(auto-fit, minmax(10.5rem, 1fr)); gap: .75rem; }
    .mn-kpi { border: 1px solid var(--mn-linea); border-radius: .75rem; padding: .75rem 1rem; display: flex; flex-direction: column; gap: .15rem; }
    .mn-kpi .valor { font-size: 1.6rem; font-weight: 700; line-height: 1.15; font-variant-numeric: tabular-nums; }
    .mn-kpi .nota { font-size: .75rem; color: var(--mn-suave); }
    .mn-alerta { display: flex; gap: .75rem; padding: .85rem 1rem; border-radius: .75rem; border: 1px solid color-mix(in srgb, var(--mn-tono) 40%, transparent); background: color-mix(in srgb, var(--mn-tono) 9%, transparent); }
    .mn-alerta--error { --mn-tono: var(--danger-500, #ef4444); }
    .mn-alerta--aviso { --mn-tono: var(--info-500, #3b82f6); }
    .mn-alerta .titulo { font-weight: 700; }
    .mn-progreso { display: flex; flex-direction: column; gap: .3rem; }
    .mn-progreso .barra { height: .6rem; border-radius: 999px; background: var(--mn-tinte); overflow: hidden; }
    .mn-progreso .barra > span { display: block; height: 100%; border-radius: 999px; background: var(--primary-500, #f59e0b); transition: width .5s; }

    /* pestaña Menú */
    .mn-menu { display: flex; flex-direction: column; gap: 1rem; }
    .mn-perfiles { display: flex; flex-wrap: wrap; gap: .5rem; }
    .mn-perfil { display: inline-flex; align-items: center; gap: .5rem; padding: .3rem .75rem .3rem .35rem; border: 1px solid var(--mn-linea); border-radius: 999px; }
    .mn-avatar { display: inline-grid; place-items: center; width: 1.5rem; height: 1.5rem; border-radius: 50%; background: var(--mn-tinte); border: 1px solid var(--mn-linea); font-size: .75rem; font-weight: 700; flex: none; }
    .mn-ayuda { display: flex; flex-wrap: wrap; justify-content: space-between; gap: .5rem 1.5rem; padding: .7rem 1rem; border-radius: .75rem; background: var(--mn-tinte); }
    .mn-nav { display: flex; flex-wrap: wrap; gap: .4rem; }
    .mn-pill { display: inline-flex; gap: .4rem; align-items: baseline; padding: .25rem .7rem; border: 1px solid var(--mn-linea); border-radius: 999px; text-decoration: none; color: inherit; font-weight: 600; }
    .mn-pill:hover { background: var(--mn-tinte); }
    .mn-pill small { font-weight: 400; color: var(--mn-suave); }
    .mn-dia { scroll-margin-top: 5rem; }
    .mn-dia-cab { display: flex; flex-wrap: wrap; align-items: baseline; justify-content: space-between; gap: .25rem 1rem; padding-bottom: .5rem; margin-bottom: .75rem; border-bottom: 1px solid var(--mn-linea); }
    .mn-dia-cab h3 { font-size: 1.1rem; font-weight: 700; }
    .mn-tomas { display: grid; grid-template-columns: repeat(auto-fit, minmax(23rem, 1fr)); gap: 1rem; align-items: start; }
    .mn-toma { --mn-color: var(--info-500, #3b82f6); border: 1px solid var(--mn-linea); border-top: 3px solid var(--mn-color); border-radius: .75rem; overflow: hidden; }
    .mn-toma--cena { --mn-color: var(--success-500, #22c55e); }
    .mn-toma-cab { display: flex; align-items: center; gap: .5rem; padding: .55rem .8rem; background: color-mix(in srgb, var(--mn-color) 10%, transparent); }
    .mn-toma-cab .nombre { font-weight: 700; letter-spacing: .04em; text-transform: uppercase; font-size: .8rem; color: var(--mn-color); }
    .mn-toma-cab .mn-icono { color: var(--mn-color); }
    .mn-toma-cab .disp { margin-left: auto; font-size: .75rem; color: var(--mn-suave); }
    .mn-op { border-top: 1px solid var(--mn-linea); }
    .mn-op:first-of-type { border-top: 0; }
    .mn-op-fila { display: flex; align-items: center; gap: .65rem; width: 100%; padding: .65rem .8rem; background: none; border: 0; font: inherit; color: inherit; text-align: left; cursor: pointer; }
    .mn-op-fila:hover { background: var(--mn-tinte); }
    .mn-op-fila:focus-visible { outline: 2px solid var(--mn-color); outline-offset: -2px; }
    .mn-radio { flex: none; width: 1.1rem; height: 1.1rem; border-radius: 50%; border: 2px solid var(--mn-suave); }
    .mn-op--elegida { background: color-mix(in srgb, var(--mn-color) 6%, transparent); box-shadow: inset 3px 0 0 var(--mn-color); }
    .mn-op--elegida .mn-radio { border-color: var(--mn-color); background: radial-gradient(circle, var(--mn-color) 0 42%, transparent 48%); }
    .mn-op-titulo { flex: 1; font-weight: 600; }
    .mn-op--elegida .mn-op-titulo { font-weight: 700; font-size: .95rem; }
    .mn-op-meta { font-size: .75rem; color: var(--mn-suave); white-space: nowrap; }
    .mn-etiqueta-eleccion { font-size: .6875rem; font-weight: 600; padding: .05rem .45rem; border-radius: 999px; border: 1px solid color-mix(in srgb, var(--mn-color) 45%, transparent); color: var(--mn-color); white-space: nowrap; }
    .mn-op-chips { display: flex; flex-wrap: wrap; gap: .25rem; padding: 0 .8rem .6rem 2.55rem; margin-top: -.3rem; }
    .mn-detalle { display: grid; gap: .9rem; padding: .15rem .8rem 1rem 2.55rem; }
    .mn-bloque { display: flex; flex-direction: column; gap: .35rem; }
    .mn-ing { list-style: none; padding: 0; display: grid; grid-template-columns: repeat(auto-fill, minmax(12.5rem, 1fr)); gap: .15rem .75rem; }
    .mn-ing li { display: flex; gap: .4rem; align-items: baseline; }
    .mn-ing .marca { flex: none; width: 1rem; text-align: center; font-weight: 700; }
    .mn-ing .comprar { font-weight: 600; }
    .mn-ing .comprar .marca { color: var(--primary-600, #d97706); }
    .mn-ing .encasa { color: var(--mn-suave); }
    .mn-pasos { list-style: decimal; padding-left: 1.3rem; display: flex; flex-direction: column; gap: .2rem; }   /* el reset de Filament quita la numeración */
    .mn-sr { position: absolute; width: 1px; height: 1px; overflow: hidden; clip: rect(0, 0, 0, 0); white-space: nowrap; }
    .mn-persona-fila { display: flex; gap: .6rem; align-items: baseline; }
    .mn-persona-fila .quien { flex: none; min-width: 4.5rem; font-weight: 700; }
    .mn-ind-tomas { display: flex; flex-direction: column; gap: .2rem; }
    .mn-individuales, .mn-explicaciones { margin-top: .6rem; padding: .6rem .8rem; border: 1px solid var(--mn-borde, rgba(128, 128, 128, .25)); border-radius: .5rem; }
    .mn-nocina { padding: .9rem .8rem; color: var(--mn-suave); font-style: italic; }
    .mn-vacio { display: flex; flex-direction: column; align-items: center; gap: .5rem; padding: 2.5rem 1rem; text-align: center; color: var(--mn-suave); }
    .mn-vacio .mn-icono { width: 2.25rem; height: 2.25rem; }

    /* pestaña Consumo */
    .mn-consumo { display: flex; flex-direction: column; gap: 1.5rem; }
    .mn-total { display: grid; grid-template-columns: minmax(12rem, 1.4fr) repeat(auto-fit, minmax(8rem, 1fr)); gap: .75rem; }
    .mn-total .mn-kpi--grande { background: color-mix(in srgb, var(--primary-500, #f59e0b) 9%, transparent); border-color: color-mix(in srgb, var(--primary-500, #f59e0b) 35%, transparent); }
    .mn-total .mn-kpi--grande .valor { font-size: 2rem; }
    .mn-tabla { width: 100%; border-collapse: collapse; }
    .mn-tabla th { text-align: left; padding: .4rem .6rem; font-size: .6875rem; font-weight: 600; letter-spacing: .06em; text-transform: uppercase; color: var(--mn-suave); border-bottom: 1px solid var(--mn-linea); }
    .mn-tabla td { padding: .6rem; border-bottom: 1px solid var(--mn-linea); vertical-align: middle; }
    .mn-tabla .num { text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; }
    .mn-tabla th.num { text-align: right; }
    .mn-tabla td.fase { font-weight: 600; }
    .mn-cuota { display: flex; align-items: center; gap: .5rem; min-width: 6rem; }
    .mn-cuota .barra { flex: 1; height: .4rem; border-radius: 999px; background: var(--mn-tinte); overflow: hidden; }
    .mn-cuota .barra > span { display: block; height: 100%; background: var(--primary-500, #f59e0b); border-radius: 999px; }
    .mn-detalles summary { cursor: pointer; font-weight: 600; }
    .mn-detalles dl { display: grid; grid-template-columns: repeat(auto-fit, minmax(11rem, 1fr)); gap: .6rem 1.5rem; margin: .75rem 0 0; }
    .mn-detalles dt { font-size: .6875rem; font-weight: 600; letter-spacing: .06em; text-transform: uppercase; color: var(--mn-suave); }
    .mn-detalles dd { margin: 0; font-variant-numeric: tabular-nums; }

    .mn-scroll { overflow-x: auto; max-width: 100%; }
    .mn-scroll:focus-visible { outline: 2px solid var(--primary-500, #f59e0b); }
    .mn { max-width: 100%; min-width: 0; overflow-wrap: anywhere; }
    .mn-chip { white-space: normal; }

    /* botones de la cabecera de la ficha: que envuelvan en vez de desbordar */
    .fi-page:has(.mn) .fi-header { flex-wrap: wrap; }
    .fi-page:has(.mn) .fi-header .fi-ac { flex-wrap: wrap; }

    @media (max-width: 640px) {
        .mn { font-size: .9375rem; }
        .mn-tomas { grid-template-columns: 1fr; }
        .mn-total { grid-template-columns: 1fr 1fr; }
        .mn-total .mn-kpi--grande { grid-column: 1 / -1; }
        .mn-kpis { grid-template-columns: 1fr 1fr; }
        .mn-ayuda { flex-direction: column; }
        .mn-nav { flex-wrap: nowrap; overflow-x: auto; padding: .4rem 0; margin: 0 -.25rem; scrollbar-width: none; background: color-mix(in srgb, Canvas 94%, transparent); }
        :root.dark .mn-nav, .dark .mn-nav { background: color-mix(in srgb, rgb(var(--gray-950, 3 7 18)) 92%, transparent); }
        .mn-pill { flex: none; min-height: 2.75rem; align-items: center; padding: .25rem .9rem; }
        /* navegación pegajosa bajo la barra superior de Filament (4 rem) */
        .mn-nav { position: sticky; top: 4rem; z-index: 6; }
        .mn-dia { scroll-margin-top: 11rem; }
        .mn-dia-cab { position: sticky; top: 7.25rem; z-index: 5; padding-top: .35rem; background: color-mix(in srgb, Canvas 94%, transparent); }
        :root.dark .mn-dia-cab, .dark .mn-dia-cab { background: color-mix(in srgb, rgb(var(--gray-950, 3 7 18)) 92%, transparent); }
        .mn-dia-cab h3 { font-size: 1.15rem; }
        /* objetivos táctiles de 44 px; la marca «Cambiada» y la etiqueta pasan a su propia línea si no caben */
        .mn-op-fila { min-height: 2.75rem; padding: .75rem .8rem; flex-wrap: wrap; row-gap: .15rem; }
        .mn-op-titulo { flex-basis: calc(100% - 2.5rem); }
        .mn-op-meta, .mn-etiqueta-eleccion { margin-left: 1.75rem; white-space: normal; }
        .mn-radio { width: 1.35rem; height: 1.35rem; }
        .mn-op-chips { padding-left: 2.75rem; }
        .mn-detalle { padding-left: .8rem; }
        .mn-ing { grid-template-columns: 1fr; }
        .mn-toma-cab { flex-wrap: wrap; }
        .mn-toma-cab .disp { flex-basis: 100%; margin-left: 0; }
        .mn-persona-fila { flex-direction: column; gap: .1rem; }
        .mn-cuota { min-width: 4rem; }
        .mn-tabla th, .mn-tabla td { padding: .5rem .4rem; }
        .mn-ident { gap: .75rem 1.5rem; }
        .fi-page:has(.mn) .fi-header .fi-btn, .fi-page:has(.mn) .fi-header .fi-ac-btn-action { min-height: 2.75rem; }
    }
    @media (min-width: 641px) and (max-width: 900px) { .mn-tomas { grid-template-columns: 1fr; } }
    @media (prefers-reduced-motion: reduce) { .mn-progreso .barra > span { transition: none; } .mn * { scroll-behavior: auto; } }
    @supports (padding: max(0px)) { .mn-nav { padding-left: max(.25rem, env(safe-area-inset-left)); padding-right: max(.25rem, env(safe-area-inset-right)); } }
</style>
@endonce
