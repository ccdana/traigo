@if ($paginator->hasPages())
    <nav aria-label="Páginas" class="flex flex-wrap items-center justify-between gap-3 pt-8 text-sm">
        @if ($paginator->onFirstPage())
            <span class="btn-soft cursor-default text-muted">← Anterior</span>
        @else
            <a class="btn-soft" href="{{ $paginator->previousPageUrl() }}" rel="prev">← Anterior</a>
        @endif

        <p class="font-mono text-xs text-muted">Página {{ $paginator->currentPage() }} de {{ $paginator->lastPage() }}</p>

        @if ($paginator->hasMorePages())
            <a class="btn-soft" href="{{ $paginator->nextPageUrl() }}" rel="next">Siguiente →</a>
        @else
            <span class="btn-soft cursor-default text-muted">Siguiente →</span>
        @endif
    </nav>
@endif
