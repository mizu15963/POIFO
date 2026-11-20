@if ($paginator->hasPages())
    <div class="pager">
        <nav>
            @if (! $paginator->onFirstPage())<a class="btn btn-outline" href="{{ $paginator->previousPageUrl() }}">前へ</a>@endif
            <span class="muted">{{ $paginator->currentPage() }} / {{ $paginator->lastPage() }} ページ（全 {{ number_format($paginator->total()) }} 件）</span>
            @if ($paginator->hasMorePages())<a class="btn btn-outline" href="{{ $paginator->nextPageUrl() }}">次へ</a>@endif
        </nav>
    </div>
@endif
