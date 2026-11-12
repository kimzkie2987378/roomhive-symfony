// RoomHive — scroll-progress island
(function() {
    const container = document.getElementById('react-scrollprogress');
    if (!container) return;
    const bar = document.createElement('div');
    bar.style.cssText = 'position:fixed;top:0;left:0;height:3px;background:var(--rh-accent,#eda423);width:0%;z-index:9999;transition:width 0.1s ease-out;pointer-events:none;';
    container.appendChild(bar);
    function updateProgress() {
        const total = document.documentElement.scrollHeight - window.innerHeight;
        if (total > 0) {
            const pct = Math.min(100, Math.max(0, (window.scrollY / total) * 100));
            bar.style.width = pct + '%';
        }
    }
    window.addEventListener('scroll', updateProgress, { passive: true });
    updateProgress();
})();
