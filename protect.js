// Content protection — disable copy, right-click, drag, text selection
document.addEventListener('contextmenu', function(e) { e.preventDefault(); });
document.addEventListener('selectstart', function(e) { e.preventDefault(); });
document.addEventListener('dragstart', function(e) { e.preventDefault(); });
document.addEventListener('copy', function(e) { e.preventDefault(); });
document.addEventListener('keydown', function(e) {
  if ((e.ctrlKey || e.metaKey) && (e.key === 's' || e.key === 'S' || e.key === 'u' || e.key === 'U' || e.key === 'p' || e.key === 'P')) {
    e.preventDefault();
  }
});
document.querySelectorAll('img').forEach(function(img) {
  img.setAttribute('draggable', 'false');
  img.style.pointerEvents = 'none';
});
