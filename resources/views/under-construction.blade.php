{{-- Tela do Gerenciamento Ágil ainda em construção: mesmo cabeçalho das demais telas e um aviso. --}}
<div class="page" id="page-{{ $screen }}">
  <header class="cap-hero">
    <div class="cap-heading"><img src="{{ asset('yuv-logo.png') }}" alt="YUV"><span class="cap-divider"></span><div><h1>{{ $title }}</h1><p>{{ $subtitle }}</p></div></div>
  </header>
  <section class="under-construction" aria-label="{{ $title }} em construção">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><path d="M2 20h20"/><path d="M5 20l3-12h8l3 12"/><path d="M7 14h10M9 8l1-4h4l1 4"/></svg>
    <h2>Em construção</h2>
    <p>{{ $description }}</p>
    <small>Faz parte do Gerenciamento Ágil, junto com Sprints e Sprint Planning.</small>
  </section>
</div>
