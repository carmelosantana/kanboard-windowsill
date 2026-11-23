import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const src = readFileSync(new URL('../../Assets/js/windowsill.js', import.meta.url), 'utf8');

const ORIGIN = 'https://kb.example';
const FRESH = '<section class="windowsill" data-windowsill-state="fresh">…</section>';

// A fake element. Live elements throw on innerHTML so any HTML parsing into the page fails the test.
function el({ tag = 'section', classes = ['windowsill'], attrs = {}, forecast = false, live = true } = {}) {
  const node = {
    tag, classes, attrs, children: [], text: null, parentNode: null,
    getAttribute: (k) => (k in attrs ? attrs[k] : null),
    setAttribute: (k, v) => { attrs[k] = String(v); },
    removeAttribute: (k) => { delete attrs[k]; },
    matches: (sel) => sel === 'section.windowsill' && tag === 'section' && classes.includes('windowsill'),
    querySelector: (sel) => (sel === '.windowsill-now' && forecast ? {} : null),
    appendChild(child) { this.children.push(child); child.parentNode = this; return child; },
    set textContent(v) { this.text = v; this.children = []; },
    get textContent() { return this.text; },
  };
  if (live) {
    Object.defineProperty(node, 'innerHTML', { set() { throw new Error('innerHTML must not be assigned on a live element'); } });
  }
  return node;
}

// A fake document. <template> parsing is a lookup: fragments maps response text → top-level elements.
function fakeDoc(elements, fragments = {}) {
  const parent = {
    replaced: [],
    replaceChild(next, old) { this.replaced.push([next, old]); next.parentNode = this; old.parentNode = null; return old; },
  };
  elements.forEach((e) => { e.parentNode = parent; });
  return {
    readyState: 'loading', parent, // defers the automatic run; tests call refreshAll themselves
    location: { href: ORIGIN + '/?controller=DashboardController&action=show', origin: ORIGIN },
    querySelectorAll: (sel) => (sel === '[data-windowsill-refresh]' ? elements.filter((e) => e.getAttribute('data-windowsill-refresh') !== null) : []),
    addEventListener() {},
    createElement(tag) {
      if (tag === 'template') {
        const tpl = { content: { children: [] } };
        Object.defineProperty(tpl, 'innerHTML', {
          set(v) { tpl.content.children = fragments[v] || [el({ tag: 'div', classes: [], live: false })]; },
        });
        return tpl;
      }
      const node = el({ tag, classes: [], live: false });
      return node;
    },
  };
}

function load(doc, extra = {}) {
  const win = {
    fetch() { throw new Error('auto refresh must use the injected fetch in tests'); },
    setTimeout() { throw new Error('no timer expected'); },
    location: doc.location,
    ...extra,
  };
  new Function('window', 'document', src)(win, doc);
  return win.KBWindowsill;
}

const response = (body, extra = {}) => ({ ok: true, redirected: false, text: () => Promise.resolve(body), ...extra });

function recorder(result) {
  const calls = [];
  const fn = (url, opts) => { calls.push([url, opts]); return typeof result === 'function' ? result() : Promise.resolve(result); };
  fn.calls = calls;
  return fn;
}

const pending = (url = '/?controller=WindowsillController&action=widget&plugin=Windowsill&csrf_token=t', forecast = false) =>
  el({ attrs: { 'data-windowsill-state': 'pending', 'data-windowsill-refresh': url }, forecast });

test('fetches a same-origin URL once and swaps in the fragment', async () => {
  const widget = pending();
  const fresh = el({ attrs: { 'data-windowsill-state': 'fresh' }, live: false });
  const doc = fakeDoc([widget], { [FRESH]: [fresh] });
  const fetchFn = recorder(response(FRESH));

  await load(doc).refreshAll(doc, fetchFn);

  assert.equal(fetchFn.calls.length, 1);
  assert.equal(fetchFn.calls[0][0], ORIGIN + '/?controller=WindowsillController&action=widget&plugin=Windowsill&csrf_token=t');
  assert.equal(fetchFn.calls[0][1].credentials, 'same-origin');
  assert.equal(fetchFn.calls[0][1].headers['X-Requested-With'], 'XMLHttpRequest');
  assert.deepEqual(doc.parent.replaced, [[fresh, widget]]);
});

test('skips a cross-origin URL without fetching', async () => {
  const widget = pending('https://evil.example/x');
  const doc = fakeDoc([widget]);
  const fetchFn = recorder(response(FRESH));

  await load(doc).refreshAll(doc, fetchFn);

  assert.equal(fetchFn.calls.length, 0);
  assert.deepEqual(doc.parent.replaced, []);
});

test('does nothing when no element has the attribute', async () => {
  const doc = fakeDoc([el({ attrs: { 'data-windowsill-state': 'fresh' } })]);
  const fetchFn = recorder(response(FRESH));

  await load(doc).refreshAll(doc, fetchFn);

  assert.equal(fetchFn.calls.length, 0);
});

const LOGIN = '<form method="post" action="/?controller=AuthController&action=check"><input name="username"></form>';
const TWO = FRESH + FRESH;
const failures = {
  'not ok': () => Promise.resolve(response(FRESH, { ok: false, status: 500 })),
  redirected: () => Promise.resolve(response(FRESH, { redirected: true })),
  'a login page': () => Promise.resolve(response(LOGIN)),
  'two sections': () => Promise.resolve(response(TWO)),
  'a rejected fetch': () => Promise.reject(new TypeError('network down')),
};
const fragments = () => ({
  [FRESH]: [el({ live: false })],
  [TWO]: [el({ live: false }), el({ live: false })],
  [LOGIN]: [el({ tag: 'form', classes: [], live: false })],
});

for (const [name, result] of Object.entries(failures)) {
  test(`on ${name} a widget with a forecast keeps it and says it couldn't refresh`, async () => {
    const widget = pending(undefined, true);
    const doc = fakeDoc([widget], fragments());

    await load(doc).refreshAll(doc, recorder(result));

    assert.deepEqual(doc.parent.replaced, []);
    assert.equal(widget.text, null, 'content kept');
    assert.equal(widget.children.length, 1);
    assert.equal(widget.children[0].textContent, "Couldn't refresh.");
  });

  test(`on ${name} a placeholder shows the unavailable text`, async () => {
    const widget = pending();
    const doc = fakeDoc([widget], fragments());

    await load(doc).refreshAll(doc, recorder(result));

    assert.deepEqual(doc.parent.replaced, []);
    assert.equal(widget.text, 'Weather is unavailable right now.');
  });
}

// A fake setTimeout that records timers; the test fires them by hand.
function fakeTimers() {
  const timers = [];
  const setTimeout = (fn, ms) => { timers.push({ fn, ms }); return timers.length; };
  return { timers, setTimeout };
}

const throttled = (n) => `<section class="windowsill" data-windowsill-state="pending" data-windowsill-retry-after="2">${n}</section>`;
const throttledEl = (url) =>
  el({ attrs: { 'data-windowsill-state': 'pending', 'data-windowsill-refresh': url, 'data-windowsill-retry-after': '2' }, live: false });

test('a throttled answer schedules exactly one retry after retry-after seconds', async () => {
  const widget = pending();
  const next = throttledEl('/?controller=WindowsillController&action=widget&plugin=Windowsill&csrf_token=t');
  const fresh = el({ attrs: { 'data-windowsill-state': 'fresh' }, live: false });
  const doc = fakeDoc([widget], { [throttled(1)]: [next], [FRESH]: [fresh] });
  const { timers, setTimeout } = fakeTimers();
  const bodies = [throttled(1), FRESH];
  const fetchFn = recorder(() => Promise.resolve(response(bodies.shift())));

  await load(doc, { setTimeout }).refreshAll(doc, fetchFn);

  assert.equal(timers.length, 1);
  assert.equal(timers[0].ms, 2000);
  assert.deepEqual(doc.parent.replaced, [[next, widget]]);

  await timers[0].fn();
  assert.equal(fetchFn.calls.length, 2);
  assert.deepEqual(doc.parent.replaced[1], [fresh, next]);
  assert.equal(timers.length, 1, 'a fresh answer schedules nothing');
});

test('after two retries a third throttled answer is left alone', async () => {
  const widget = pending();
  const url = '/?controller=WindowsillController&action=widget&plugin=Windowsill&csrf_token=t';
  const els = [throttledEl(url), throttledEl(url), throttledEl(url)];
  const doc = fakeDoc([widget], { [throttled(1)]: [els[0]], [throttled(2)]: [els[1]], [throttled(3)]: [els[2]] });
  const { timers, setTimeout } = fakeTimers();
  const bodies = [throttled(1), throttled(2), throttled(3)];
  const fetchFn = recorder(() => Promise.resolve(response(bodies.shift())));

  await load(doc, { setTimeout }).refreshAll(doc, fetchFn);
  assert.equal(timers.length, 1);
  await timers[0].fn();
  assert.equal(timers.length, 2);
  await timers[1].fn();

  assert.equal(fetchFn.calls.length, 3);
  assert.equal(timers.length, 2, 'no third retry');
  assert.deepEqual(doc.parent.replaced[2], [els[2], els[1]]);
});

test('a pending answer without retry-after schedules nothing', async () => {
  const widget = pending();
  const body = '<section class="windowsill" data-windowsill-state="pending">x</section>';
  const doc = fakeDoc([widget], { [body]: [el({ attrs: { 'data-windowsill-state': 'pending', 'data-windowsill-refresh': '/x' }, live: false })] });

  await load(doc).refreshAll(doc, recorder(response(body)));

  assert.equal(doc.parent.replaced.length, 1);
});
