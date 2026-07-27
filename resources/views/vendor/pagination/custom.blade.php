@if ($paginator->hasPages())
    <nav role="navigation" aria-label="Pagination Navigation" style="display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:12px; padding:14px 0 6px; border-top:1px solid #e2e8f0; margin-top:16px;">
        
        {{-- Showing info text --}}
        <div style="font-size:0.82rem; color:#64748b;">
            Menampilkan <span style="font-weight:700; color:#0f172a;">{{ $paginator->firstItem() ?? 0 }}</span> &ndash; <span style="font-weight:700; color:#0f172a;">{{ $paginator->lastItem() ?? 0 }}</span> dari <span style="font-weight:700; color:#0f172a;">{{ $paginator->total() }}</span> data
        </div>

        {{-- Page buttons --}}
        <div style="display:flex; align-items:center; gap:4px;">
            {{-- Previous Page Link --}}
            @if ($paginator->onFirstPage())
                <span style="padding:6px 12px; border-radius:8px; font-size:0.8rem; font-weight:600; background:#f1f5f9; color:#94a3b8; border:1px solid #e2e8f0; cursor:not-allowed;">&laquo; Prev</span>
            @else
                <a href="{{ $paginator->previousPageUrl() }}" style="padding:6px 12px; border-radius:8px; font-size:0.8rem; font-weight:600; background:#ffffff; color:#2563eb; border:1px solid #cbd5e1; text-decoration:none; box-shadow:0 1px 2px rgba(0,0,0,0.04); transition:all 0.15s ease;">&laquo; Prev</a>
            @endif

            {{-- Pagination Elements --}}
            @foreach ($elements as $element)
                {{-- "Three Dots" Separator --}}
                @if (is_string($element))
                    <span style="padding:6px 10px; font-size:0.8rem; color:#94a3b8;">{{ $element }}</span>
                @endif

                {{-- Array Of Links --}}
                @if (is_array($element))
                    @foreach ($element as $page => $url)
                        @if ($page == $paginator->currentPage())
                            <span style="padding:6px 12px; border-radius:8px; font-size:0.8rem; font-weight:700; background:#2563eb; color:#ffffff; border:1px solid #2563eb; box-shadow:0 2px 6px rgba(37,99,235,0.3);">{{ $page }}</span>
                        @else
                            <a href="{{ $url }}" style="padding:6px 12px; border-radius:8px; font-size:0.8rem; font-weight:600; background:#ffffff; color:#475569; border:1px solid #cbd5e1; text-decoration:none; box-shadow:0 1px 2px rgba(0,0,0,0.04); transition:all 0.15s ease;">{{ $page }}</a>
                        @endif
                    @endforeach
                @endif
            @endforeach

            {{-- Next Page Link --}}
            @if ($paginator->hasMorePages())
                <a href="{{ $paginator->nextPageUrl() }}" style="padding:6px 12px; border-radius:8px; font-size:0.8rem; font-weight:600; background:#ffffff; color:#2563eb; border:1px solid #cbd5e1; text-decoration:none; box-shadow:0 1px 2px rgba(0,0,0,0.04); transition:all 0.15s ease;">Next &raquo;</a>
            @else
                <span style="padding:6px 12px; border-radius:8px; font-size:0.8rem; font-weight:600; background:#f1f5f9; color:#94a3b8; border:1px solid #e2e8f0; cursor:not-allowed;">Next &raquo;</span>
            @endif
        </div>
    </nav>
@endif
