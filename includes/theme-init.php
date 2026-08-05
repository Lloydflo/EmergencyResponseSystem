<script>
(function() {
    var theme = 'system';

    try {
        theme = localStorage.getItem('ers-theme') || 'system';
    } catch (_) {}

    var prefersDark = false;
    try {
        prefersDark = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches;
    } catch (_) {}

    var resolvedTheme = theme === 'system'
        ? (prefersDark ? 'dark' : 'light')
        : theme;

    document.documentElement.setAttribute('data-theme', resolvedTheme);
    document.documentElement.style.colorScheme = resolvedTheme;
})();
</script>
<link rel="preconnect" href="https://cdnjs.cloudflare.com" crossorigin>
<link rel="dns-prefetch" href="//cdnjs.cloudflare.com">
<link rel="preconnect" href="https://unpkg.com" crossorigin>
<link rel="dns-prefetch" href="//unpkg.com">
<link rel="preconnect" href="https://www.gstatic.com" crossorigin>
<link rel="dns-prefetch" href="//www.gstatic.com">
