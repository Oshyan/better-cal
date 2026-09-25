// A scroll bar that stays on screen, for touch devices.
//
// Android Chrome and iOS overlay their scroll bars and hide them the moment
// scrolling stops, and ignore CSS that tries to style or pin them. An area
// with more in it should look like it has more in it at rest, so on those
// devices the bar is drawn instead: a thin thumb in a rail beside the
// scroller, moved as it scrolls. It shows position, it is not a control:
// you scroll by dragging the content, as everywhere on a phone. The paired
// edge shadows (.bc-scroll-edges in app.css) mark "more above / below" too.
//
// attachScrollRail(el) -> detach(). el's parent must be positioned.

export function attachScrollRail(el) {
  const parent = el.parentElement;
  if (!parent) return () => {};
  const rail = document.createElement('div');
  rail.className = 'bc-scroll-rail';
  rail.setAttribute('aria-hidden', 'true');
  const thumb = document.createElement('div');
  thumb.className = 'bc-scroll-thumb';
  rail.appendChild(thumb);
  parent.appendChild(rail);
  el.classList.add('has-rail');

  let queued = false;
  const paint = () => {
    queued = false;
    const { scrollHeight, clientHeight, scrollTop, offsetTop } = el;
    if (scrollHeight <= clientHeight + 2) { rail.hidden = true; return; }
    rail.hidden = false;
    const inset = 6; // clear of rounded corners at either end
    const track = Math.max(0, clientHeight - inset * 2);
    rail.style.top = `${offsetTop + inset}px`;
    rail.style.height = `${track}px`;
    const size = Math.max(32, Math.round((clientHeight / scrollHeight) * track));
    const travel = track - size;
    const at = Math.round((scrollTop / (scrollHeight - clientHeight)) * travel);
    thumb.style.height = `${size}px`;
    thumb.style.transform = `translateY(${Math.max(0, Math.min(travel, at))}px)`;
  };
  // One paint per frame however many scroll, resize or content events land.
  const update = () => {
    if (queued) return;
    queued = true;
    requestAnimationFrame(paint);
  };
  el.addEventListener('scroll', update, { passive: true });
  const ro = new ResizeObserver(update);
  ro.observe(el);
  const mo = new MutationObserver(update);
  mo.observe(el, { childList: true, subtree: true, characterData: true });
  update();
  return () => {
    el.removeEventListener('scroll', update);
    ro.disconnect();
    mo.disconnect();
    rail.remove();
    el.classList.remove('has-rail');
  };
}
